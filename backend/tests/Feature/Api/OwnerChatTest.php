<?php

use App\Models\BlacklistTerm;
use App\Models\Car;
use App\Models\ChatMessage;
use App\Models\ModerationEvent;
use App\Services\Chat\ChatService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['wasla.moderation.api_key' => 'sk-test', 'wasla.moderation.daily_cap' => 5000]);
    Cache::flush();
    Http::fake(['api.openai.com/*' => Http::response(['results' => [['category_scores' => ['harassment' => 0.01]]]])]);
});

it('lists only threads of the owner cars', function () {
    $user = owner();
    $mine = chatThread(linkedCar($user));
    chatThread(linkedCar(owner()));

    $this->getJson('/api/v1/chats', bearer($user))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->id)
        ->assertJsonPath('data.0.unread_count', 1)
        ->assertJsonPath('data.0.last_message.sender', 'visitor');
});

it('forbids another user on every chat route', function (string $method, string $uri, array $body) {
    $thread = chatThread(linkedCar(owner()));
    $message = $thread->messages()->first();
    $uri = str_replace(['{thread}', '{message}'], [$thread->id, $message->id], $uri);

    $this->json($method, $uri, $body, bearer(owner()))->assertForbidden();

    expect($thread->messages()->count())->toBe(1)
        ->and($thread->fresh()->blocked_at)->toBeNull()
        ->and($message->fresh()->reported_at)->toBeNull();
})->with([
    'messages' => ['GET', '/api/v1/chats/{thread}/messages', []],
    'send' => ['POST', '/api/v1/chats/{thread}/messages', ['body' => 'hello']],
    'block' => ['POST', '/api/v1/chats/{thread}/block', []],
    'report' => ['POST', '/api/v1/messages/{message}/report', []],
]);

it('requires a signed in owner', function () {
    $this->getJson('/api/v1/chats')->assertUnauthorized();
});

it('returns messages in id order and only those after the given id', function () {
    $user = owner();
    $thread = chatThread(linkedCar($user), 'one');
    $second = $thread->messages()->create(['sender' => 'visitor', 'body' => 'two']);
    $third = $thread->messages()->create(['sender' => 'owner', 'body' => 'three']);

    $this->getJson("/api/v1/chats/{$thread->id}/messages", bearer($user))
        ->assertOk()->assertJsonPath('data.*.body', ['one', 'two', 'three']);

    $this->getJson("/api/v1/chats/{$thread->id}/messages?after={$second->id}", bearer($user))
        ->assertOk()->assertJsonPath('data.*.id', [$third->id]);
});

it('marks visitor messages read once the owner fetched them', function () {
    $user = owner();
    $thread = chatThread(linkedCar($user));

    $this->getJson("/api/v1/chats/{$thread->id}/messages", bearer($user))
        ->assertJsonPath('data.0.read', true);

    expect($thread->messages()->whereNull('read_at')->count())->toBe(0);
});

it('lets an owner reply and stores it as an owner message', function () {
    $user = owner();
    $thread = chatThread(linkedCar($user));

    $this->postJson("/api/v1/chats/{$thread->id}/messages", ['body' => 'Thanks, on my way'], bearer($user))
        ->assertCreated()
        ->assertJsonPath('sender', 'owner')
        ->assertJsonPath('body', 'Thanks, on my way');

    expect(ChatMessage::where('sender', 'owner')->where('moderation_status', 'passed')->count())->toBe(1);
});

it('moderates owner replies', function () {
    BlacklistTerm::create(['term' => 'zibidi', 'match_mode' => 'word']);
    $user = owner();
    $thread = chatThread(linkedCar($user));

    $this->postJson("/api/v1/chats/{$thread->id}/messages", ['body' => 'sen zibidi'], bearer($user))
        ->assertUnprocessable()->assertJsonPath('code', 'message_blocked');

    expect($thread->messages()->count())->toBe(1);
    $event = ModerationEvent::firstOrFail();
    expect($event->surface)->toBe('chat')
        ->and($event->thread_id)->toBe($thread->id)
        ->and($event->user_id)->toBe($user->id);
});

it('flags a reply that passed only because the provider was unavailable', function () {
    config(['wasla.moderation.daily_cap' => 0]);
    $user = owner();
    $thread = chatThread(linkedCar($user));

    $this->postJson("/api/v1/chats/{$thread->id}/messages", ['body' => 'merhaba'], bearer($user))->assertCreated();

    expect(ChatMessage::where('sender', 'owner')->value('moderation_status'))->toBe('flagged');
});

it('limits messages to 500 characters and rejects blanks', function () {
    $user = owner();
    $thread = chatThread(linkedCar($user));
    $url = "/api/v1/chats/{$thread->id}/messages";

    $this->postJson($url, ['body' => str_repeat('a', 501)], bearer($user))->assertUnprocessable()->assertJsonValidationErrors('body');
    $this->postJson($url, ['body' => "   \u{00A0}\u{200B} "], bearer($user))->assertUnprocessable()->assertJsonValidationErrors('body');
    $this->postJson($url, [], bearer($user))->assertUnprocessable()->assertJsonValidationErrors('body');
    $this->postJson($url, ['body' => str_repeat('ş', 500)], bearer($user))->assertCreated();

    expect($thread->messages()->where('sender', 'owner')->count())->toBe(1);
});

it('does not filter a phone number an owner types', function () {
    $user = owner();
    $thread = chatThread(linkedCar($user));

    $this->postJson("/api/v1/chats/{$thread->id}/messages", ['body' => 'Ara beni: +90 532 123 45 67'], bearer($user))
        ->assertCreated()->assertJsonPath('body', 'Ara beni: +90 532 123 45 67');
});

it('hides a previous owner thread from the new owner', function () {
    $old = owner();
    $car = linkedCar($old);
    $thread = chatThread($car);
    $tag = $car->tag;

    $this->postJson("/api/v1/cars/{$car->id}/unlink", [], bearer($old))->assertOk();
    app('auth')->forgetGuards();

    $new = owner();
    $this->postJson('/api/v1/tags/claim', ['token' => $tag->token] + carAttrs(), bearer($new))->assertCreated();
    app('auth')->forgetGuards();

    $this->getJson('/api/v1/chats', bearer($new))->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/chats/{$thread->id}/messages", bearer($new))->assertForbidden();
    app('auth')->forgetGuards();

    $this->getJson('/api/v1/chats', bearer($old))->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/chats/{$thread->id}/messages", bearer($old))->assertOk()->assertJsonCount(1, 'data');
});

it('never exposes owner email or phone on any chat payload', function () {
    $user = owner();
    $car = linkedCar($user);
    $car->contacts()->create(['label' => 'Wife', 'phone' => '+905321112233', 'position' => 1]);
    $thread = chatThread($car);
    $message = $thread->messages()->first();

    $payloads = [
        $this->getJson('/api/v1/chats', bearer($user)),
        $this->getJson("/api/v1/chats/{$thread->id}/messages", bearer($user)),
        $this->postJson("/api/v1/chats/{$thread->id}/messages", ['body' => 'hi'], bearer($user)),
        $this->postJson("/api/v1/chats/{$thread->id}/block", [], bearer($user)),
        $this->postJson("/api/v1/messages/{$message->id}/report", [], bearer($user)),
    ];

    foreach ($payloads as $response) {
        $response->assertSuccessful();
        $json = $response->getContent();
        foreach ([$user->email, '5321112233', 'ip_hash', 'token_hash', '34 ABC 123'] as $secret) {
            expect($json)->not->toContain($secret);
        }
    }
});

it('stops owner replies on an expired car', function () {
    $user = owner();
    $thread = chatThread(linkedCar($user, now()->subDay()));

    $this->postJson("/api/v1/chats/{$thread->id}/messages", ['body' => 'hello'], bearer($user))
        ->assertStatus(402)->assertJsonPath('code', 'subscription_expired');

    expect($thread->messages()->count())->toBe(1);
});

it('does not reveal expiry to another user', function () {
    $thread = chatThread(linkedCar(owner(), now()->subDay()));

    $this->postJson("/api/v1/chats/{$thread->id}/messages", ['body' => 'hello'], bearer(owner()))->assertForbidden();
});

it('blocks a visitor session and sets a cooldown for that ip on that car only', function () {
    $user = owner();
    $car = linkedCar($user);
    $thread = chatThread($car);
    $other = chatThread(linkedCar($user));
    $ipHash = $thread->visitorSession->ip_hash;

    $this->postJson("/api/v1/chats/{$thread->id}/block", [], bearer($user))
        ->assertOk()->assertJsonPath('blocked', true);

    expect($thread->fresh()->blocked_at)->not->toBeNull()
        ->and($other->fresh()->blocked_at)->toBeNull()
        ->and(Cache::has(ChatService::cooldownKey($car->id, $ipHash)))->toBeTrue()
        ->and(Cache::has(ChatService::cooldownKey($other->car_id, $ipHash)))->toBeFalse();

    $this->travel(25)->hours();
    expect(Cache::has(ChatService::cooldownKey($car->id, $ipHash)))->toBeFalse();
});

it('hashes the ip with an hmac of the app key', function () {
    expect(ChatService::hashIp('10.1.1.1'))
        ->toBe(hash_hmac('sha256', '10.1.1.1', config('app.key')))
        ->not->toContain('10.1.1.1');
});

it('reports a message once and keeps the first report time', function () {
    $user = owner();
    $message = chatThread(linkedCar($user))->messages()->first();

    $this->postJson("/api/v1/messages/{$message->id}/report", [], bearer($user))
        ->assertOk()->assertJsonPath('reported', true);
    $first = $message->fresh()->reported_at;

    $this->travel(5)->minutes();
    $this->postJson("/api/v1/messages/{$message->id}/report", [], bearer($user))->assertOk();

    expect($message->fresh()->reported_at->equalTo($first))->toBeTrue();
});

it('hard stops every chat route on an expired car', function (string $method, string $uri, array $body) {
    $user = owner();
    $thread = chatThread(linkedCar($user, now()->subDay()));
    $message = $thread->messages()->first();
    $uri = str_replace(['{thread}', '{message}'], [$thread->id, $message->id], $uri);

    $this->json($method, $uri, $body, bearer($user))
        ->assertStatus(402)->assertJsonPath('code', 'subscription_expired');

    expect($thread->messages()->count())->toBe(1)
        ->and($thread->fresh()->blocked_at)->toBeNull()
        ->and($message->fresh()->reported_at)->toBeNull();
})->with([
    'messages' => ['GET', '/api/v1/chats/{thread}/messages', []],
    'send' => ['POST', '/api/v1/chats/{thread}/messages', ['body' => 'hello']],
    'block' => ['POST', '/api/v1/chats/{thread}/block', []],
    'report' => ['POST', '/api/v1/messages/{message}/report', []],
]);

it('leaves threads of expired cars out of the list but keeps unlinked and active ones', function () {
    $user = owner();
    $active = chatThread(linkedCar($user));
    chatThread(linkedCar($user, now()->subDay()));
    $unlinked = chatThread(Car::create(carAttrs() + ['user_id' => $user->id]));

    $this->getJson('/api/v1/chats', bearer($user))
        ->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonFragment(['id' => $active->id])->assertJsonFragment(['id' => $unlinked->id]);
});

it('blocks replies once the car is unlinked, expired or not', function (bool $expired) {
    $user = owner();
    $car = linkedCar($user, $expired ? now()->subDay() : null);
    $thread = chatThread($car);
    $car->tag->update(['car_id' => null]);

    $this->postJson("/api/v1/chats/{$thread->id}/messages", ['body' => 'hello'], bearer($user))
        ->assertForbidden()->assertJsonPath('code', 'chat_unavailable');

    expect($thread->messages()->count())->toBe(1);
})->with([[true], [false]]);

it('keeps history, block and report for an unlinked car', function () {
    $user = owner();
    $car = linkedCar($user);
    $thread = chatThread($car);
    $message = $thread->messages()->first();
    $car->tag->update(['car_id' => null]);

    $this->getJson("/api/v1/chats/{$thread->id}/messages", bearer($user))->assertOk()->assertJsonCount(1, 'data');
    $this->postJson("/api/v1/messages/{$message->id}/report", [], bearer($user))->assertOk();
    $this->postJson("/api/v1/chats/{$thread->id}/block", [], bearer($user))->assertOk();
});

it('stops a previous owner replying after a transfer', function () {
    $old = owner();
    $car = linkedCar($old);
    $thread = chatThread($car);
    $tag = $car->tag;

    $this->postJson("/api/v1/cars/{$car->id}/unlink", [], bearer($old))->assertOk();
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/tags/claim', ['token' => $tag->token] + carAttrs(), bearer(owner()))->assertCreated();
    app('auth')->forgetGuards();

    $this->postJson("/api/v1/chats/{$thread->id}/messages", ['body' => 'hello'], bearer($old))
        ->assertForbidden()->assertJsonPath('code', 'chat_unavailable');
});

it('refuses an owner reply to a thread they blocked', function () {
    $user = owner();
    $thread = chatThread(linkedCar($user));
    app(ChatService::class)->block($thread);

    $this->postJson("/api/v1/chats/{$thread->id}/messages", ['body' => 'Hala yaziyorum'], bearer($user))
        ->assertForbidden()
        ->assertJsonPath('code', 'chat_unavailable');
    expect($thread->messages()->count())->toBe(1);
});

it('shows one thread with the car state the reply screen needs', function () {
    $user = owner();
    $car = linkedCar($user);
    $thread = chatThread($car);

    $this->getJson("/api/v1/chats/{$thread->id}", bearer($user))
        ->assertOk()
        ->assertJsonPath('id', $thread->id)
        ->assertJsonPath('blocked', false)
        ->assertJsonPath('unread_count', 1)
        ->assertJsonPath('car.id', $car->id)
        ->assertJsonPath('car.model', $car->model)
        ->assertJsonPath('car.linked', true)
        ->assertJsonPath('car.chats_paused', false)
        ->assertJsonPath('car.subscription_active', true)
        ->assertJsonMissingPath('car.plate');
});

it('refuses to show a thread to another user, a new owner or for an expired tag', function () {
    $user = owner();
    $car = linkedCar($user);
    $thread = chatThread($car);

    $this->getJson("/api/v1/chats/{$thread->id}", bearer(owner()))->assertForbidden();
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/chats/999999', bearer($user))->assertNotFound();
    app('auth')->forgetGuards();

    $car->tag->update(['expires_at' => now()->subDay()]);
    $this->getJson("/api/v1/chats/{$thread->id}", bearer($user))->assertStatus(402)->assertJsonPath('code', 'subscription_expired');
});

it('reports a car without a tag on the thread', function () {
    $user = owner();
    $car = linkedCar($user);
    $thread = chatThread($car);
    $this->postJson("/api/v1/cars/{$car->id}/unlink", [], bearer($user))->assertOk();
    app('auth')->forgetGuards();

    $this->getJson("/api/v1/chats/{$thread->id}", bearer($user))
        ->assertOk()
        ->assertJsonPath('car.linked', false)
        ->assertJsonPath('car.subscription_active', false);
});

it('does not show an old thread to the new owner of a reclaimed tag, but still to the old owner', function () {
    $old = owner();
    $car = linkedCar($old);
    $thread = chatThread($car);
    $tag = $car->tag;

    $this->postJson("/api/v1/cars/{$car->id}/unlink", [], bearer($old))->assertOk();
    app('auth')->forgetGuards();
    $new = owner();
    $this->postJson('/api/v1/tags/claim', ['token' => $tag->token] + carAttrs(), bearer($new))->assertCreated();
    app('auth')->forgetGuards();

    $this->getJson("/api/v1/chats/{$thread->id}", bearer($new))->assertForbidden();
    app('auth')->forgetGuards();
    $this->getJson("/api/v1/chats/{$thread->id}", bearer($old))->assertOk()->assertJsonPath('car.linked', false);
});

it('blocks an owner message sent straight to the API when a word starts with a prefix term', function () {
    BlacklistTerm::create(['term' => 'zibidi', 'language' => 'tr', 'match_mode' => 'prefix']);
    $user = owner();
    $thread = chatThread(linkedCar($user));

    $this->postJson("/api/v1/chats/{$thread->id}/messages", ['body' => 'zibidiler'], bearer($user))
        ->assertUnprocessable()->assertJsonPath('code', 'message_blocked');

    expect($thread->messages()->where('body', 'zibidiler')->exists())->toBeFalse();
});
