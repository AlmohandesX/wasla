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

it('lists messages reported by owners and visitors', function () {
    $car = linkedCar(owner(['email_verified_at' => now()]));
    $thread = chatThread($car);
    $byOwner = $thread->messages()->first(); // the visitor's message, reported by the owner
    $byOwner->update(['reported_at' => now()]);
    $byVisitor = $thread->messages()->create(['sender' => 'owner', 'body' => 'reply', 'reported_at' => now()->subHour()]);
    $clean = $thread->messages()->create(['sender' => 'owner', 'body' => 'fine']);

    Livewire::test(ManageReportedMessages::class)
        ->assertCanSeeTableRecords([$byOwner, $byVisitor])
        ->assertCanNotSeeTableRecords([$clean]);

    expect(ChatMessage::whereNotNull('reported_at')->count())->toBe(2);
});
