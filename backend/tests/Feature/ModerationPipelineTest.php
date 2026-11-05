<?php

use App\Enums\ModerationDecision;
use App\Models\BlacklistTerm;
use App\Models\ModerationEvent;
use App\Services\Moderation\ModerationPipeline;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

const OPENAI_URL = 'https://api.openai.com/v1/moderations';

function openAiScores(array $scores): array
{
    return ['results' => [['flagged' => false, 'categories' => [], 'category_scores' => $scores]]];
}

function pipeline(): ModerationPipeline
{
    return app(ModerationPipeline::class);
}

beforeEach(function () {
    config([
        'wasla.moderation.api_key' => 'sk-test',
        'wasla.moderation.daily_cap' => 5000,
        'wasla.moderation.threshold' => 0.7,
        'wasla.moderation.thresholds' => [],
    ]);
    Cache::flush();
});

it('blocks a word-mode term as a whole word only', function () {
    Http::fake([OPENAI_URL => Http::response(openAiScores(['harassment' => 0.01]))]);
    BlacklistTerm::create(['term' => 'am', 'match_mode' => 'word']);

    expect(pipeline()->check('am')->decision)->toBe(ModerationDecision::Block)
        ->and(pipeline()->check('Am')->decision)->toBe(ModerationDecision::Block)
        ->and(pipeline()->check('amca geldi')->decision)->toBe(ModerationDecision::Allow);
});

it('blocks a substring-mode term inside a word', function () {
    Http::fake();
    BlacklistTerm::create(['term' => 'kotukelime', 'match_mode' => 'substring']);

    $result = pipeline()->check('süper KÖTÜKELİMEler');

    expect($result->decision)->toBe(ModerationDecision::Block)->and($result->source)->toBe('blacklist');
});

it('ignores inactive terms', function () {
    Http::fake([OPENAI_URL => Http::response(openAiScores(['harassment' => 0.01]))]);
    BlacklistTerm::create(['term' => 'kotukelime', 'match_mode' => 'substring', 'active' => false]);

    expect(pipeline()->check('kotukelime')->decision)->toBe(ModerationDecision::Allow);
});

it('does not call the provider on a blacklist hit', function () {
    Http::fake();
    BlacklistTerm::create(['term' => 'kotukelime', 'match_mode' => 'word']);

    pipeline()->check('k0tukelime');

    Http::assertNothingSent();
});

it('blocks when a category passes its threshold', function () {
    Http::fake([OPENAI_URL => Http::response(openAiScores(['harassment' => 0.92, 'violence' => 0.1]))]);

    $result = pipeline()->check('merhaba');

    expect($result->decision)->toBe(ModerationDecision::Block)
        ->and($result->source)->toBe('provider')
        ->and($result->reason)->toContain('harassment');
});

it('honours a per-category threshold override', function () {
    config(['wasla.moderation.thresholds' => ['harassment' => 0.95]]);
    Http::fake([OPENAI_URL => Http::response(openAiScores(['harassment' => 0.92]))]);

    expect(pipeline()->check('merhaba')->decision)->toBe(ModerationDecision::Allow);
});

it('allows when scores are under the threshold', function () {
    Http::fake([OPENAI_URL => Http::response(openAiScores(['harassment' => 0.2, 'violence' => 0.1]))]);

    $result = pipeline()->check('merhaba');

    expect($result->decision)->toBe(ModerationDecision::Allow)->and($result->source)->toBe('provider');
    expect(ModerationEvent::count())->toBe(0);
});

it('sends only the text', function () {
    Http::fake([OPENAI_URL => Http::response(openAiScores(['harassment' => 0.01]))]);

    pipeline()->check('merhaba', ['user_id' => 7, 'thread_id' => 9, 'surface' => 'chat']);

    Http::assertSent(function (Request $request) {
        $keys = array_keys($request->data());
        sort($keys);

        return $keys === ['input', 'model']
            && $request['input'] === 'merhaba'
            && $request['model'] === 'omni-moderation-latest';
    });
});

it('allows and flags on a 500', function () {
    Http::fake([OPENAI_URL => Http::response('boom', 500)]);

    $result = pipeline()->check('merhaba', ['surface' => 'chat']);

    expect($result->decision)->toBe(ModerationDecision::AllowFlagged)->and($result->source)->toBe('fallback');
    $event = ModerationEvent::sole();
    expect($event->kind)->toBe('flagged')->and($event->source)->toBe('fallback')->and($event->text)->toBe('merhaba');
});

it('allows and flags on a timeout', function () {
    Http::fake([OPENAI_URL => fn () => throw new ConnectionException('timed out')]);

    $result = pipeline()->check('merhaba', ['surface' => 'status']);

    expect($result->decision)->toBe(ModerationDecision::AllowFlagged)->and($result->source)->toBe('fallback');
    expect(ModerationEvent::sole()->surface)->toBe('status');
});

it('allows and flags when the daily cap is reached and does not call OpenAI', function () {
    config(['wasla.moderation.daily_cap' => 2]);
    Http::fake([OPENAI_URL => Http::response(openAiScores(['harassment' => 0.01]))]);

    pipeline()->check('bir');
    pipeline()->check('iki');
    $result = pipeline()->check('uc');

    expect($result->decision)->toBe(ModerationDecision::AllowFlagged)->and($result->source)->toBe('fallback');
    Http::assertSentCount(2);
});

it('still blocks on the blacklist during an outage', function () {
    Http::fake([OPENAI_URL => Http::response('boom', 500)]);
    BlacklistTerm::create(['term' => 'kotukelime', 'match_mode' => 'word']);

    expect(pipeline()->check('kotukelime')->decision)->toBe(ModerationDecision::Block);
});

it('records an event for each block', function () {
    Http::fake([OPENAI_URL => Http::response(openAiScores(['harassment' => 0.9]))]);
    BlacklistTerm::create(['term' => 'kotukelime', 'match_mode' => 'word']);

    pipeline()->check('kotukelime', ['surface' => 'chat', 'thread_id' => null, 'user_id' => null]);
    pipeline()->check('hakaret', ['surface' => 'contact_label']);

    $events = ModerationEvent::orderBy('id')->get();
    expect($events)->toHaveCount(2)
        ->and($events[0]->kind)->toBe('blocked')->and($events[0]->source)->toBe('blacklist')
        ->and($events[1]->kind)->toBe('blocked')->and($events[1]->source)->toBe('provider')
        ->and($events[1]->surface)->toBe('contact_label');
});

it('skips the provider when useProvider is false', function () {
    Http::fake();

    $result = pipeline()->check('merhaba', [], false);

    expect($result->decision)->toBe(ModerationDecision::Allow)->and($result->source)->toBe('blacklist');
    Http::assertNothingSent();
});

it('seeds a starter blacklist that leaves everyday Turkish words alone', function () {
    Http::fake();
    $this->seed(\Database\Seeders\BlacklistSeeder::class);

    expect(BlacklistTerm::count())->toBeGreaterThan(20)
        ->and(pipeline()->check('amca geldi', [], false)->decision)->toBe(ModerationDecision::Allow)
        ->and(pipeline()->check('Amcamın arabası', [], false)->decision)->toBe(ModerationDecision::Allow)
        ->and(pipeline()->check('siktir git', [], false)->decision)->toBe(ModerationDecision::Block)
        ->and(pipeline()->check('fuuuck you', [], false)->decision)->toBe(ModerationDecision::Block);
});


it('seeds the blacklist idempotently through the database seeder', function () {
    $this->seed(\Database\Seeders\DatabaseSeeder::class);
    $count = BlacklistTerm::count();
    $this->seed(\Database\Seeders\BlacklistSeeder::class);

    expect($count)->toBeGreaterThan(20)->and(BlacklistTerm::count())->toBe($count);
});
