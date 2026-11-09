<?php

use App\Filament\Resources\BlacklistTermResource\Pages\ManageBlacklistTerms;
use App\Filament\Resources\ModerationEventResource\Pages\ManageModerationEvents;
use App\Filament\Resources\ReportedMessageResource\Pages\ManageReportedMessages;
use App\Models\BlacklistTerm;
use App\Models\ChatMessage;
use App\Models\ModerationEvent;
use App\Services\Moderation\ModerationPipeline;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->admin = adminUser();
    $this->actingAs($this->admin);
});

it('adds a blacklist term and applies it to the next message', function () {
    Livewire::test(ManageBlacklistTerms::class)
        ->callAction(TestAction::make('create')->table(), ['term' => 'Kötü5özcük', 'match_mode' => 'word', 'active' => true])
        ->assertHasNoActionErrors();

    expect(BlacklistTerm::count())->toBe(1);
    $result = app(ModerationPipeline::class)->check('bu kötüsözcük burada', [], false);
    expect($result->blocked())->toBeTrue();
});

it('rejects a duplicate blacklist term by its normalised form instead of throwing', function () {
    BlacklistTerm::create(['term' => 'kotu', 'match_mode' => 'word']);

    Livewire::test(ManageBlacklistTerms::class)
        ->callAction(TestAction::make('create')->table(), ['term' => 'KÖTÜÜ', 'match_mode' => 'word', 'active' => true])
        ->assertHasActionErrors(['term']);

    // Same word in the other mode is a different rule.
    Livewire::test(ManageBlacklistTerms::class)
        ->callAction(TestAction::make('create')->table(), ['term' => 'kotu', 'match_mode' => 'substring', 'active' => true])
        ->assertHasNoActionErrors();

    expect(BlacklistTerm::count())->toBe(2);
});

it('rejects a term that normalises to nothing', function () {
    Livewire::test(ManageBlacklistTerms::class)
        ->callAction(TestAction::make('create')->table(), ['term' => "\u{200B}", 'match_mode' => 'word', 'active' => true])
        ->assertHasActionErrors(['term']);
});

it('lists blocked and flagged events, including one with a thread that does not exist, and marks them reviewed', function () {
    $blocked = ModerationEvent::create(['kind' => 'blocked', 'surface' => 'chat', 'text' => 'bad', 'source' => 'blacklist', 'thread_id' => 987654]);
    $flagged = ModerationEvent::create(['kind' => 'flagged', 'surface' => 'status', 'text' => 'hmm', 'source' => 'provider']);

    Livewire::test(ManageModerationEvents::class)
        ->assertCanSeeTableRecords([$blocked, $flagged])
        ->filterTable('kind', 'blocked')
        ->assertCanSeeTableRecords([$blocked])->assertCanNotSeeTableRecords([$flagged])
        ->resetTableFilters()
        ->callAction(TestAction::make('markReviewed')->table($flagged))
        ->assertNotified(__('admin.moderation.reviewed'));

    expect($flagged->fresh()->reviewed_at)->not->toBeNull()
        ->and($flagged->fresh()->reviewed_by)->toBe($this->admin->id)
        ->and($blocked->fresh()->reviewed_at)->toBeNull();

    Livewire::test(ManageModerationEvents::class)
        ->filterTable('unreviewed')
        ->assertCanSeeTableRecords([$blocked])->assertCanNotSeeTableRecords([$flagged]);
});

it('lists messages reported through the owner and the visitor endpoints', function () {
    config(['wasla.moderation.api_key' => 'sk-test', 'wasla.moderation.daily_cap' => 5000]);
    Http::fake(['api.openai.com/*' => Http::response(['results' => [['category_scores' => ['harassment' => 0.01]]]])]);
    $ownerUser = owner(['email_verified_at' => now()]);
    $tag = linkedCar($ownerUser)->tag;

    // A visitor writes, and the owner reports that message.
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->withCredentials()->withUnencryptedCookie('wasla_visitor', '');
    $sent = $this->postJson("/t/{$tag->token}/chat/messages", ['body' => 'Isiklariniz acik kalmis'])->assertCreated();
    $cookie = $sent->getCookie('wasla_visitor', false)->getValue();
    $visitorMessage = ChatMessage::sole();
    auth()->logout(); // the panel session would otherwise win over the bearer token
    app('auth')->forgetGuards();
    $this->postJson("/api/v1/messages/{$visitorMessage->id}/report", [], bearer($ownerUser))->assertOk();

    // The owner answers, and the visitor reports that reply.
    $ownerMessage = $visitorMessage->thread->messages()->create(['sender' => 'owner', 'body' => 'reply']);
    $clean = $visitorMessage->thread->messages()->create(['sender' => 'owner', 'body' => 'fine']);
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->withCredentials()->withUnencryptedCookie('wasla_visitor', $cookie);
    $this->postJson("/t/{$tag->token}/chat/messages/{$ownerMessage->id}/report")->assertOk();

    $this->actingAs($this->admin);
    Livewire::test(ManageReportedMessages::class)
        ->assertCanSeeTableRecords([$visitorMessage, $ownerMessage])
        ->assertCanNotSeeTableRecords([$clean])
        ->assertSee(__('admin.reported.visitor'))->assertSee(__('admin.reported.owner'));
});

it('translates the moderation surface and source', function () {
    ModerationEvent::create(['kind' => 'blocked', 'surface' => 'default_message', 'text' => 'bad', 'source' => 'fallback']);

    Livewire::test(ManageModerationEvents::class)
        ->assertSee(__('admin.moderation.surfaces.default_message'))->assertSee(__('admin.moderation.sources.fallback'));
});

it('adds an Arabic prefix term from the admin panel and blocks suffixed forms', function () {
    Livewire::test(ManageBlacklistTerms::class)
        ->callAction(TestAction::make('create')->table(), ['term' => 'غبي', 'language' => 'ar', 'match_mode' => 'prefix', 'active' => true])
        ->assertHasNoActionErrors();

    $term = BlacklistTerm::firstOrFail();
    expect($term->language)->toBe('ar')->and($term->match_mode)->toBe('prefix')
        ->and(app(ModerationPipeline::class)->check('انت غبيه', [], false)->blocked())->toBeTrue();
});

it('rejects an unknown language or match mode', function () {
    Livewire::test(ManageBlacklistTerms::class)
        ->callAction(TestAction::make('create')->table(), ['term' => 'kotu', 'language' => 'fr', 'match_mode' => 'regex', 'active' => true])
        ->assertHasActionErrors(['language', 'match_mode']);
});
