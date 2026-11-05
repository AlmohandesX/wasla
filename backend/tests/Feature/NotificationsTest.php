<?php

use App\Events\ChatMessageCreated;
use App\Models\ChatMessage;
use App\Models\DeviceToken;
use App\Models\ModerationEvent;
use App\Models\Reminder;
use App\Models\VisitorSession;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

const EXPO_URL = 'https://exp.host/--/api/v2/push/send';

/** Replaces any earlier fake, since the first matching stub otherwise wins. */
function fakeExpo(mixed $response): void
{
    Http::swap($factory = new Factory);
    $factory->fake([EXPO_URL => $response]);
}

beforeEach(fn () => fakeExpo(Http::response(['data' => [['status' => 'ok', 'id' => 'ticket']]])));

/** Owner with one push token and a car linked to a tag that expires at $expiresAt. */
function pushOwner(?DateTimeInterface $expiresAt = null, string $locale = 'tr'): array
{
    $user = owner(['locale' => $locale]);
    $user->deviceTokens()->create(['token' => 'ExponentPushToken[abc'.$user->id.']', 'platform' => 'android']);

    return [$user, linkedCar($user, $expiresAt)];
}

function expoMessages(): array
{
    return Http::recorded()->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $r) => $r->url() === EXPO_URL)
        ->flatMap(fn (Request $r) => $r->data())
        ->values()->all();
}

it('sends a reminder 7 days before, 1 day before and on the due date', function () {
    Carbon::setTestNow('2026-11-05 10:00:00 UTC');
    [, $car] = pushOwner();
    foreach (['2026-11-12', '2026-11-06', '2026-11-05', '2026-11-09', '2026-11-04'] as $due) {
        $car->reminders()->create(['type' => 'oil_change', 'due_on' => $due]);
    }

    $this->artisan('reminders:send')->assertSuccessful();

    $messages = expoMessages();
    expect($messages)->toHaveCount(3)
        ->and(collect($messages)->pluck('data.days')->sort()->values()->all())->toBe([0, 1, 7])
        ->and($messages[0]['to'])->toStartWith('ExponentPushToken[')
        ->and($messages[0]['title'])->toBe('Yağ değişimi hatırlatması');
});

it('uses the Istanbul calendar date', function () {
    // 21:30 UTC on the 5th is 00:30 on the 6th in Istanbul.
    Carbon::setTestNow('2026-11-05 21:30:00 UTC');
    [, $car] = pushOwner();
    $car->reminders()->create(['type' => 'license_renewal', 'due_on' => '2026-11-06']);
    $car->reminders()->create(['type' => 'license_renewal', 'due_on' => '2026-11-05']);

    $this->artisan('reminders:send')->assertSuccessful();

    $messages = expoMessages();
    expect($messages)->toHaveCount(1)->and($messages[0]['data']['days'])->toBe(0);
});

it('never sends the same notification twice', function () {
    Carbon::setTestNow('2026-11-05 10:00:00 UTC');
    [, $car] = pushOwner(Carbon::parse('2026-11-12 10:00:00 UTC'));
    $car->reminders()->create(['type' => 'oil_change', 'due_on' => '2026-11-12']);

    foreach ([1, 2] as $_) {
        $this->artisan('reminders:send')->assertSuccessful();
        $this->artisan('reminders:expiry')->assertSuccessful();
    }

    expect(expoMessages())->toHaveCount(2);
});

it('translates push text in the owner locale', function () {
    Carbon::setTestNow('2026-11-05 10:00:00 UTC');
    [, $car] = pushOwner(null, 'en');
    $car->reminders()->create(['type' => 'oil_change', 'due_on' => '2026-11-06']);

    $this->artisan('reminders:send');

    expect(expoMessages()[0]['title'])->toBe('Oil change reminder');
});

it('sends expiry reminders at 30, 7 and 1 days', function () {
    Carbon::setTestNow('2026-11-05 10:00:00 UTC');
    foreach ([30, 7, 1, 15] as $days) {
        pushOwner(now()->addDays($days));
    }

    $this->artisan('reminders:expiry')->assertSuccessful();

    expect(collect(expoMessages())->pluck('data.days')->sort()->values()->all())->toBe([1, 7, 30]);
});

it('notifies the owner of a new visitor message without its text', function () {
    [$user, $car] = pushOwner();
    $thread = chatThread($car, 'gizli mesaj govdesi');

    ChatMessageCreated::dispatch($thread->messages()->first());

    $messages = expoMessages();
    expect($messages)->toHaveCount(1)
        ->and($messages[0]['to'])->toBe($user->deviceTokens()->first()->token)
        ->and($messages[0]['data']['thread_id'])->toBe($thread->id)
        ->and(json_encode($messages))->not->toContain('gizli mesaj govdesi');
});

it('does not push for an owner reply', function () {
    [, $car] = pushOwner();
    $reply = chatThread($car)->messages()->create(['sender' => 'owner', 'body' => 'tamam']);

    ChatMessageCreated::dispatch($reply);

    Http::assertNothingSent();
});

it('removes a token Expo reports as DeviceNotRegistered', function () {
    fakeExpo(Http::response(['data' => [
        ['status' => 'error', 'message' => 'gone', 'details' => ['error' => 'DeviceNotRegistered']],
    ]]));
    [$user, $car] = pushOwner();

    ChatMessageCreated::dispatch(chatThread($car)->messages()->first());

    expect($user->deviceTokens()->count())->toBe(0);
});

it('sends to Expo in batches of 100', function () {
    $user = owner();
    foreach (range(1, 150) as $i) {
        $user->deviceTokens()->create(['token' => "ExponentPushToken[t$i]"]);
    }
    fakeExpo(fn (Request $r) => Http::response(['data' => array_fill(0, count($r->data()), ['status' => 'ok'])]));

    ChatMessageCreated::dispatch(chatThread(linkedCar($user))->messages()->first());

    Http::assertSentCount(2);
});

it('registers and removes a device token', function () {
    $user = owner();
    $body = ['token' => 'ExponentPushToken[xyz]', 'platform' => 'ios'];

    $this->postJson('/api/v1/device-tokens', $body, bearer($user))->assertNoContent();
    $this->postJson('/api/v1/device-tokens', $body, bearer($user))->assertNoContent();
    expect($user->deviceTokens()->count())->toBe(1);

    $this->postJson('/api/v1/device-tokens', ['token' => 'not-a-token'], bearer($user))->assertUnprocessable();

    $this->deleteJson('/api/v1/device-tokens', ['token' => 'ExponentPushToken[xyz]'], bearer($user))->assertNoContent();
    expect($user->deviceTokens()->count())->toBe(0);
});

it('moves a token to the account that registers it last and never deletes another account token', function () {
    $first = owner();
    $second = owner();
    $first->deviceTokens()->create(['token' => 'ExponentPushToken[shared]']);

    $this->deleteJson('/api/v1/device-tokens', ['token' => 'ExponentPushToken[shared]'], bearer($second))->assertNoContent();
    expect(DeviceToken::count())->toBe(1);

    $this->postJson('/api/v1/device-tokens', ['token' => 'ExponentPushToken[shared]'], bearer($second))->assertNoContent();
    expect(DeviceToken::first()->user_id)->toBe($second->id);
});

it('creates, lists, updates and deletes reminders for the owner', function () {
    $user = owner();
    $car = linkedCar($user);
    $due = now('Europe/Istanbul')->addDays(10)->toDateString();

    $id = $this->postJson("/api/v1/cars/{$car->id}/reminders", ['type' => 'oil_change', 'due_on' => $due], bearer($user))
        ->assertCreated()->assertJsonPath('due_on', $due)->json('id');
    $this->postJson("/api/v1/cars/{$car->id}/reminders", ['type' => 'tyres', 'due_on' => $due], bearer($user))->assertUnprocessable();
    $this->postJson("/api/v1/cars/{$car->id}/reminders", ['type' => 'oil_change', 'due_on' => '2001-01-01'], bearer($user))->assertUnprocessable();

    $this->getJson("/api/v1/cars/{$car->id}/reminders", bearer($user))->assertOk()->assertJsonCount(1, 'data');
    $this->patchJson("/api/v1/reminders/$id", ['due_on' => now('Europe/Istanbul')->addDays(20)->toDateString()], bearer($user))->assertOk();
    $this->deleteJson("/api/v1/reminders/$id", [], bearer($user))->assertNoContent();
    expect(Reminder::count())->toBe(0);
});

it('forbids another user on reminder routes', function (string $method, string $uri, array $body) {
    $car = linkedCar(owner());
    $reminder = $car->reminders()->create(['type' => 'oil_change', 'due_on' => now()->addDays(5)->toDateString()]);
    $uri = str_replace(['{car}', '{reminder}'], [$car->id, $reminder->id], $uri);

    $this->json($method, $uri, $body, bearer(owner()))->assertForbidden();

    expect(Reminder::count())->toBe(1)->and($reminder->fresh()->type)->toBe('oil_change');
})->with([
    ['GET', '/api/v1/cars/{car}/reminders', []],
    ['POST', '/api/v1/cars/{car}/reminders', ['type' => 'oil_change', 'due_on' => '2030-01-01']],
    ['PATCH', '/api/v1/reminders/{reminder}', ['type' => 'license_renewal']],
    ['DELETE', '/api/v1/reminders/{reminder}', []],
]);

it('stops reminders for a car on an expired subscription', function () {
    Carbon::setTestNow('2026-11-05 10:00:00 UTC');
    [$user, $car] = pushOwner(now()->subDay());
    $reminder = $car->reminders()->create(['type' => 'oil_change', 'due_on' => '2026-11-06']);

    $this->artisan('reminders:send')->assertSuccessful();
    Http::assertNothingSent();

    $this->getJson("/api/v1/cars/{$car->id}/reminders", bearer($user))->assertStatus(402);
    $this->postJson("/api/v1/cars/{$car->id}/reminders", ['type' => 'oil_change', 'due_on' => '2026-12-01'], bearer($user))->assertStatus(402);
    $this->deleteJson("/api/v1/reminders/{$reminder->id}", [], bearer($user))->assertStatus(402);
});

it('purges chats older than 90 days', function () {
    $car = linkedCar(owner());
    $old = chatThread($car);
    $old->messages()->update(['created_at' => now()->subDays(91)]);
    $old->forceFill(['updated_at' => now()->subDays(91)])->saveQuietly();
    $event = ModerationEvent::create(['kind' => 'blocked', 'surface' => 'chat', 'text' => 'x', 'source' => 'blacklist', 'thread_id' => 9999]);
    ModerationEvent::whereKey($event->id)->update(['created_at' => now()->subDays(91)]);
    VisitorSession::whereKey($old->visitor_session_id)->update(['last_seen_at' => now()->subDays(91)]);

    $this->artisan('chats:purge')->assertSuccessful();

    expect(ChatMessage::count())->toBe(0)
        ->and($old->newQuery()->whereKey($old->id)->exists())->toBeFalse()
        ->and(VisitorSession::whereKey($old->visitor_session_id)->exists())->toBeFalse()
        ->and(ModerationEvent::count())->toBe(0);
});

it('keeps chats younger than 90 days', function () {
    $car = linkedCar(owner());
    $recent = chatThread($car);
    $recent->messages()->update(['created_at' => now()->subDays(89)]);
    $recent->forceFill(['updated_at' => now()->subDays(89)])->saveQuietly();
    $event = ModerationEvent::create(['kind' => 'blocked', 'surface' => 'chat', 'text' => 'x', 'source' => 'blacklist']);
    ModerationEvent::whereKey($event->id)->update(['created_at' => now()->subDays(89)]);

    $this->artisan('chats:purge')->assertSuccessful();

    expect(ChatMessage::count())->toBe(1)
        ->and($recent->newQuery()->whereKey($recent->id)->exists())->toBeTrue()
        ->and(VisitorSession::whereKey($recent->visitor_session_id)->exists())->toBeTrue()
        ->and(ModerationEvent::count())->toBe(1);
});

it('keeps a thread that is active even if its first messages are old', function () {
    $car = linkedCar(owner());
    $thread = chatThread($car);
    $thread->messages()->update(['created_at' => now()->subDays(120)]);
    $thread->messages()->create(['sender' => 'owner', 'body' => 'yeni']);

    $this->artisan('chats:purge')->assertSuccessful();

    expect(ChatMessage::count())->toBe(1)->and($thread->newQuery()->whereKey($thread->id)->exists())->toBeTrue();
});

it('keeps a young empty thread and its visitor session', function () {
    $car = linkedCar(owner());
    $thread = chatThread($car);
    $thread->messages()->delete();

    $this->artisan('chats:purge')->assertSuccessful();

    expect($thread->newQuery()->whereKey($thread->id)->exists())->toBeTrue()
        ->and(VisitorSession::whereKey($thread->visitor_session_id)->exists())->toBeTrue();
});

it('schedules the daily jobs in Istanbul time', function () {
    $events = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events());

    foreach (['reminders:send' => '0 9 * * *', 'reminders:expiry' => '0 9 * * *', 'chats:purge' => '0 3 * * *'] as $name => $cron) {
        $event = $events->first(fn ($e) => str_contains($e->command, $name));
        expect($event)->not->toBeNull()
            ->and($event->expression)->toBe($cron)
            ->and($event->timezone)->toBe('Europe/Istanbul');
    }
});
