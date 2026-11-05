<?php

use App\Enums\PublicTagState;
use App\Models\BlacklistTerm;
use App\Models\Car;
use App\Models\EmergencyContact;
use App\Models\ModerationEvent;
use App\Models\Tag;
use App\Services\Tags\PublicTagStateResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['wasla.moderation.api_key' => 'sk-test', 'wasla.moderation.daily_cap' => 5000]);
    Cache::flush();
    Http::fake(['api.openai.com/*' => Http::response(['results' => [['category_scores' => ['harassment' => 0.01]]]])]);
});

it('does not let an unverified account claim a tag', function () {
    $user = owner(['email_verified_at' => null]);
    $tag = tagFixture();

    $this->postJson('/api/v1/tags/claim', ['token' => $tag->token] + carAttrs(), bearer($user))
        ->assertForbidden()->assertJsonPath('code', 'email_not_verified');
    expect($tag->refresh()->car_id)->toBeNull();
});

it('claims a tag into a new car and returns subscription info', function () {
    $user = owner();
    $tag = tagFixture();

    $this->postJson('/api/v1/tags/claim', ['token' => $tag->token] + carAttrs(), bearer($user))
        ->assertCreated()
        ->assertJsonPath('model', 'Renault Clio')
        ->assertJsonPath('subscription.active', true)
        ->assertJsonPath('linked', true)
        ->assertJsonMissingPath('user_id');
    expect($tag->refresh()->car_id)->not->toBeNull();
});

it('renders claim errors as code and message JSON', function () {
    $user = owner();
    $this->postJson('/api/v1/tags/claim', ['token' => 'aaaaaaaaaaaaaaaa'] + carAttrs(), bearer($user))
        ->assertNotFound()->assertJsonPath('code', 'tag_not_found')->assertJsonStructure(['code', 'message']);

    $taken = linkedCar(owner());
    $this->postJson('/api/v1/tags/claim', ['token' => $taken->tag->token] + carAttrs(), bearer($user))
        ->assertStatus(409)->assertJsonPath('code', 'tag_already_claimed');
});

it('links a tag to an existing car but never to someone elses', function () {
    $user = owner();
    $mine = Car::create(carAttrs(['user_id' => $user->id]));
    $theirs = Car::create(carAttrs(['user_id' => owner()->id]));
    $tag = tagFixture();

    $this->postJson('/api/v1/tags/claim', ['token' => $tag->token, 'car_id' => $theirs->id], bearer($user))
        ->assertForbidden()->assertJsonPath('code', 'car_not_owned');
    $this->postJson('/api/v1/tags/claim', ['token' => $tag->token, 'car_id' => 999999], bearer($user))
        ->assertForbidden()->assertJsonPath('code', 'car_not_owned');
    $this->postJson('/api/v1/tags/claim', ['token' => $tag->token, 'car_id' => $mine->id], bearer($user))
        ->assertOk()->assertJsonPath('id', $mine->id);
});

it('rate limits claims to 10 per minute per user', function () {
    $headers = bearer(owner());
    foreach (range(1, 10) as $i) {
        $this->postJson('/api/v1/tags/claim', ['token' => 'aaaaaaaaaaaaaaaa'] + carAttrs(), $headers)->assertNotFound();
    }
    $this->postJson('/api/v1/tags/claim', ['token' => 'aaaaaaaaaaaaaaaa'] + carAttrs(), $headers)->assertStatus(429);
});

it('creates, lists, updates and deletes only the owners own cars', function () {
    $user = owner();
    $headers = bearer($user);
    linkedCar(owner());

    $id = $this->postJson('/api/v1/cars', carAttrs(['default_message' => 'Merhaba']), $headers)
        ->assertCreated()->assertJsonPath('linked', false)->json('id');
    $this->getJson('/api/v1/cars', $headers)->assertOk()->assertJsonCount(1, 'data');
    $this->patchJson("/api/v1/cars/$id", ['model' => 'Fiat Egea'], $headers)->assertOk()->assertJsonPath('model', 'Fiat Egea');
    $this->deleteJson("/api/v1/cars/$id", [], $headers)->assertNoContent();
    expect(Car::find($id))->toBeNull();
});

it('deleting a linked car releases its tag with dates kept', function () {
    $user = owner();
    $car = linkedCar($user);
    $expires = $car->tag->expires_at;

    $this->deleteJson("/api/v1/cars/{$car->id}", [], bearer($user))->assertNoContent();

    $tag = Tag::first();
    expect($tag->car_id)->toBeNull()->and($tag->expires_at->equalTo($expires))->toBeTrue();
});

it('cannot set user_id, stealth or tag fields through car create or update', function () {
    $user = owner();
    $other = owner();
    $headers = bearer($user);
    $payload = ['user_id' => $other->id, 'stealth_enabled' => true, 'chats_paused' => true, 'status_text' => 'x',
        'status_expires_at' => now()->addDay()->toIso8601String(), 'expires_at' => now()->addYears(9)->toIso8601String(), 'tag_id' => 1];

    $id = $this->postJson('/api/v1/cars', carAttrs($payload), $headers)->assertCreated()->json('id');
    $car = Car::find($id);
    expect($car->user_id)->toBe($user->id)->and($car->stealth_enabled)->toBeFalse()
        ->and($car->chats_paused)->toBeFalse()->and($car->status_text)->toBeNull();

    $this->patchJson("/api/v1/cars/$id", $payload, $headers)->assertOk();
    $car->refresh();
    expect($car->user_id)->toBe($user->id)->and($car->stealth_enabled)->toBeFalse()->and($car->status_text)->toBeNull();
});

it('applies stealth immediately', function () {
    $user = owner();
    $car = linkedCar($user);
    $resolver = app(PublicTagStateResolver::class);
    expect($resolver->resolve(Tag::find($car->tag->id)))->toBe(PublicTagState::Active);

    $this->putJson("/api/v1/cars/{$car->id}/stealth", ['enabled' => true], bearer($user))
        ->assertOk()->assertJsonPath('stealth_enabled', true);

    expect($resolver->resolve(Tag::find($car->tag->id)))->toBe(PublicTagState::Neutral);

    $this->putJson("/api/v1/cars/{$car->id}/stealth", ['enabled' => false], bearer($user))->assertOk();
    expect($resolver->resolve(Tag::find($car->tag->id)))->toBe(PublicTagState::Active);
});

it('pauses and resumes chats', function () {
    $user = owner();
    $car = linkedCar($user);
    $this->putJson("/api/v1/cars/{$car->id}/chats-paused", ['paused' => true], bearer($user))
        ->assertOk()->assertJsonPath('chats_paused', true);
    $this->putJson("/api/v1/cars/{$car->id}/chats-paused", ['paused' => 'nope'], bearer($user))->assertUnprocessable();
});

it('reverts to the default message at status expiry', function () {
    $user = owner();
    $car = linkedCar($user, null, ['default_message' => 'Hemen donerim']);

    $this->putJson("/api/v1/cars/{$car->id}/status", ['text' => 'Market, 10 dk', 'duration_minutes' => 30], bearer($user))
        ->assertOk()->assertJsonPath('current_message', 'Market, 10 dk');
    expect($car->refresh()->currentMessage())->toBe('Market, 10 dk');

    $this->travel(31)->minutes();
    expect(Car::find($car->id)->currentMessage())->toBe('Hemen donerim');

    $this->deleteJson("/api/v1/cars/{$car->id}/status", [], bearer($user))->assertOk();
    expect(Car::find($car->id)->status_text)->toBeNull();
});

it('falls back to the translated default when no default message is set', function () {
    $car = Car::create(carAttrs(['user_id' => owner()->id]));
    expect($car->currentMessage())->toBe(__('wasla.default_message'))->not->toBe('wasla.default_message');
});

it('limits status text to 140 characters', function () {
    $user = owner();
    $car = linkedCar($user);

    $this->putJson("/api/v1/cars/{$car->id}/status", ['text' => str_repeat('a', 141), 'duration_minutes' => 30], bearer($user))
        ->assertUnprocessable()->assertJsonValidationErrors('text');
    $this->putJson("/api/v1/cars/{$car->id}/status", ['text' => str_repeat('a', 140), 'duration_minutes' => 30], bearer($user))->assertOk();
    $this->putJson("/api/v1/cars/{$car->id}/status", ['text' => 'ok', 'duration_minutes' => 0], bearer($user))
        ->assertUnprocessable()->assertJsonValidationErrors('duration_minutes');
});

it('blocks toxic status, default message and contact labels', function () {
    BlacklistTerm::create(['term' => 'zibidi', 'match_mode' => 'word']);
    $user = owner();
    $headers = bearer($user);
    $car = linkedCar($user, null, ['default_message' => 'temiz']);

    $this->putJson("/api/v1/cars/{$car->id}/status", ['text' => 'sen zibidi', 'duration_minutes' => 30], $headers)
        ->assertUnprocessable()->assertJsonPath('code', 'message_blocked');
    $this->patchJson("/api/v1/cars/{$car->id}", ['default_message' => 'zibidi'], $headers)
        ->assertUnprocessable()->assertJsonPath('code', 'message_blocked');
    $this->postJson('/api/v1/cars', carAttrs(['default_message' => 'zibidi']), $headers)
        ->assertUnprocessable()->assertJsonPath('code', 'message_blocked');
    $this->postJson("/api/v1/cars/{$car->id}/contacts", ['label' => 'zibidi', 'phone' => '+905321234567'], $headers)
        ->assertUnprocessable()->assertJsonPath('code', 'message_blocked');

    $car->refresh();
    expect($car->status_text)->toBeNull()->and($car->default_message)->toBe('temiz')
        ->and(Car::count())->toBe(1)->and(EmergencyContact::count())->toBe(0)
        ->and(ModerationEvent::pluck('surface')->sort()->values()->all())
        ->toBe(['contact_label', 'default_message', 'default_message', 'status']);
});

it('returns 402 subscription_expired for an expired car', function () {
    $user = owner();
    $car = linkedCar($user, now()->subDay());
    $headers = bearer($user);

    $this->getJson("/api/v1/cars/{$car->id}/contacts", $headers)
        ->assertStatus(402)->assertJsonPath('code', 'subscription_expired')->assertJsonStructure(['code', 'message']);
    $this->putJson("/api/v1/cars/{$car->id}/stealth", ['enabled' => true], $headers)->assertStatus(402);
    $this->patchJson("/api/v1/cars/{$car->id}", ['model' => 'X'], $headers)->assertStatus(402);
    $this->putJson("/api/v1/cars/{$car->id}/status", ['text' => 'a', 'duration_minutes' => 5], $headers)->assertStatus(402);
});

it('keeps other paid cars working when one has expired', function () {
    $user = owner();
    $expired = linkedCar($user, now()->subDay());
    $paid = linkedCar($user);
    $headers = bearer($user);

    $this->getJson("/api/v1/cars/{$expired->id}/contacts", $headers)->assertStatus(402);
    $this->getJson("/api/v1/cars/{$paid->id}/contacts", $headers)->assertOk();
});

it('still allows show, list and unlink on an expired car', function () {
    $user = owner();
    $car = linkedCar($user, now()->subDay());
    $headers = bearer($user);

    $this->getJson('/api/v1/cars', $headers)->assertOk()->assertJsonPath('data.0.subscription.active', false);
    $this->getJson("/api/v1/cars/{$car->id}", $headers)->assertOk()->assertJsonPath('subscription.active', false);
    $this->postJson("/api/v1/cars/{$car->id}/unlink", [], $headers)->assertOk()->assertJsonPath('linked', false);
    expect(Tag::first()->car_id)->toBeNull();
});

it('does not block a car with no tag', function () {
    $user = owner();
    $car = Car::create(carAttrs(['user_id' => $user->id]));
    $this->getJson("/api/v1/cars/{$car->id}/contacts", bearer($user))->assertOk();
});

it('does not reveal expiry to a user who does not own the car', function () {
    $car = linkedCar(owner(), now()->subDay());
    $this->getJson("/api/v1/cars/{$car->id}/contacts", bearer(owner()))->assertForbidden();
});

it('rejects whitespace-only text', function () {
    $user = owner();
    $car = linkedCar($user);
    foreach (['   ', "\u{00A0}\u{200B} ", "\t\n"] as $blank) {
        $this->putJson("/api/v1/cars/{$car->id}/status", ['text' => $blank, 'duration_minutes' => 5], bearer($user))
            ->assertUnprocessable()->assertJsonValidationErrors('text');
    }
    $this->postJson("/api/v1/cars/{$car->id}/contacts", ['label' => '  ', 'phone' => '+905321234567'], bearer($user))
        ->assertUnprocessable()->assertJsonValidationErrors('label');
});

it('allows emoji and Turkish characters in text', function () {
    $user = owner();
    $car = linkedCar($user);
    $text = "Şişli'deyim 🚗 çok geç kalmam ğüöıİ";

    $this->putJson("/api/v1/cars/{$car->id}/status", ['text' => $text, 'duration_minutes' => 5], bearer($user))
        ->assertOk()->assertJsonPath('current_message', $text);
    $this->postJson("/api/v1/cars/{$car->id}/contacts", ['label' => 'Annem 👩', 'phone' => '+905321234567'], bearer($user))
        ->assertCreated()->assertJsonPath('label', 'Annem 👩');
});

it('validates Turkish and international phone numbers on contacts', function () {
    $user = owner();
    $car = linkedCar($user);
    $post = fn (string $phone) => $this->postJson("/api/v1/cars/{$car->id}/contacts", ['label' => 'Ev', 'phone' => $phone], bearer($user));

    $post('0532 123 45 67')->assertCreated()->assertJsonPath('phone', '+905321234567');
    $post('5321234567')->assertCreated()->assertJsonPath('phone', '+905321234567');
    $post('+90 (532) 123-45-67')->assertCreated()->assertJsonPath('phone', '+905321234567');
    $post('+44 20 7946 0958')->assertCreated()->assertJsonPath('phone', '+442079460958');
    $post('0044 20 7946 0958')->assertCreated()->assertJsonPath('phone', '+442079460958');

    foreach (['abc', '12345', '+0123456789', '+9053212', '0532 123 45', '+1234567890123456', '05321234567; DROP', ''] as $bad) {
        $post($bad)->assertUnprocessable()->assertJsonValidationErrors('phone');
    }
});

it('lists, updates and deletes contacts in position order', function () {
    $user = owner();
    $car = linkedCar($user);
    $headers = bearer($user);

    $a = $this->postJson("/api/v1/cars/{$car->id}/contacts", ['label' => 'A', 'phone' => '+905321234567'], $headers)->assertCreated()->json();
    $b = $this->postJson("/api/v1/cars/{$car->id}/contacts", ['label' => 'B', 'phone' => '+905329876543'], $headers)->json();
    expect($b['position'])->toBeGreaterThan($a['position']);

    $this->getJson("/api/v1/cars/{$car->id}/contacts", $headers)->assertOk()->assertJsonPath('data.0.label', 'A')->assertJsonPath('data.1.label', 'B');
    $this->patchJson("/api/v1/contacts/{$a['id']}", ['label' => 'Anne'], $headers)->assertOk()->assertJsonPath('label', 'Anne');
    $this->deleteJson("/api/v1/contacts/{$b['id']}", [], $headers)->assertNoContent();
    expect(EmergencyContact::count())->toBe(1);
});

it('applies the expiry hard stop to contact routes by contact', function () {
    $user = owner();
    $car = linkedCar($user);
    $contact = EmergencyContact::create(['car_id' => $car->id, 'label' => 'A', 'phone' => '+905321234567', 'position' => 1]);
    $car->tag->update(['expires_at' => now()->subMinute()]);

    $this->patchJson("/api/v1/contacts/{$contact->id}", ['label' => 'B'], bearer($user))->assertStatus(402);
    $this->deleteJson("/api/v1/contacts/{$contact->id}", [], bearer($user))->assertStatus(402);
});

it('rate limits writes to 30 per minute per user', function () {
    $user = owner();
    $car = linkedCar($user);
    $headers = bearer($user);
    foreach (range(1, 30) as $i) {
        $this->putJson("/api/v1/cars/{$car->id}/chats-paused", ['paused' => true], $headers)->assertOk();
    }
    $this->putJson("/api/v1/cars/{$car->id}/chats-paused", ['paused' => true], $headers)->assertStatus(429);
});
