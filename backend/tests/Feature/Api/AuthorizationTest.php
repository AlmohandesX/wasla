<?php

use App\Models\Car;
use App\Models\EmergencyContact;

/** Every car and contact route: [method, uri template, body]. {car} and {contact} belong to user A. */
dataset('car routes', [
    'list' => ['GET', '/api/v1/cars', []],
    'create' => ['POST', '/api/v1/cars', ['model' => 'A', 'plate' => 'B']],
    'show' => ['GET', '/api/v1/cars/{car}', []],
    'update' => ['PATCH', '/api/v1/cars/{car}', ['model' => 'Z']],
    'delete' => ['DELETE', '/api/v1/cars/{car}', []],
    'claim' => ['POST', '/api/v1/tags/claim', ['token' => 'aaaaaaaaaaaaaaaa', 'car_id' => '{car}']],
    'unlink' => ['POST', '/api/v1/cars/{car}/unlink', []],
    'stealth' => ['PUT', '/api/v1/cars/{car}/stealth', ['enabled' => true]],
    'chats paused' => ['PUT', '/api/v1/cars/{car}/chats-paused', ['paused' => true]],
    'set status' => ['PUT', '/api/v1/cars/{car}/status', ['text' => 'hi', 'duration_minutes' => 5]],
    'clear status' => ['DELETE', '/api/v1/cars/{car}/status', []],
    'list contacts' => ['GET', '/api/v1/cars/{car}/contacts', []],
    'add contact' => ['POST', '/api/v1/cars/{car}/contacts', ['label' => 'A', 'phone' => '+905321234567']],
    'update contact' => ['PATCH', '/api/v1/contacts/{contact}', ['label' => 'B']],
    'delete contact' => ['DELETE', '/api/v1/contacts/{contact}', []],
]);

function fillRoute(string $uri, Car $car, EmergencyContact $contact): string
{
    return str_replace(['{car}', '{contact}'], [(string) $car->id, (string) $contact->id], $uri);
}

function fillBody(array $body, Car $car): array
{
    return array_map(fn ($v) => $v === '{car}' ? $car->id : $v, $body);
}

/** @return array{0: Car, 1: EmergencyContact} */
function carWithContact(): array
{
    $car = linkedCar(owner());
    $contact = EmergencyContact::create(['car_id' => $car->id, 'label' => 'A', 'phone' => '+905321234567', 'position' => 1]);

    return [$car, $contact];
}

it('forbids user B on every route for user A car', function (string $method, string $uri, array $body) {
    [$car, $contact] = carWithContact();
    $watched = fn () => [
        $car->fresh()->only(['model', 'stealth_enabled', 'chats_paused', 'status_text']),
        $contact->fresh()?->only(['label', 'phone']),
        $car->tag->fresh()->car_id,
        Car::where('user_id', $car->user_id)->count(),
    ];
    $before = $watched();

    $response = $this->json($method, fillRoute($uri, $car, $contact), fillBody($body, $car), bearer(owner()));

    if ($uri === '/api/v1/cars') {
        // Collection routes are scoped to the caller: they never expose or touch A's data.
        expect($response->status())->toBeIn([200, 201]);
        if ($method === 'GET') {
            $response->assertJsonCount(0, 'data');
        }
    } else {
        expect($response->status())->toBeIn([403, 404]);
    }
    expect($watched())->toBe($before);
})->with('car routes');

it('rejects unauthenticated requests on every route', function (string $method, string $uri, array $body) {
    [$car, $contact] = carWithContact();

    $this->json($method, fillRoute($uri, $car, $contact), fillBody($body, $car))->assertUnauthorized();
})->with('car routes');

it('forbids a suspended user on every route', function (string $method, string $uri, array $body) {
    [$car, $contact] = carWithContact();
    $user = $car->user;
    $headers = bearer($user);
    $user->forceFill(['suspended_at' => now()])->save();

    $this->json($method, fillRoute($uri, $car, $contact), fillBody($body, $car), $headers)->assertForbidden();
})->with('car routes');

it('requires a verified email on every route', function (string $method, string $uri, array $body) {
    [$car, $contact] = carWithContact();
    $user = $car->user;
    $headers = bearer($user);
    $user->forceFill(['email_verified_at' => null])->save();

    $this->json($method, fillRoute($uri, $car, $contact), fillBody($body, $car), $headers)
        ->assertForbidden()->assertJsonPath('code', 'email_not_verified');
})->with('car routes');
