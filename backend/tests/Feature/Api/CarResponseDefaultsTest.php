<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['wasla.moderation.api_key' => 'sk-test', 'wasla.moderation.daily_cap' => 5000]);
    Cache::flush();
    Http::fake(['api.openai.com/*' => Http::response(['results' => [['category_scores' => ['harassment' => 0.01]]]])]);
});

it('returns the database defaults for the switches when a tag creates a new car', function () {
    $this->postJson('/api/v1/tags/claim', ['token' => tagFixture()->token] + carAttrs(), bearer(owner()))
        ->assertCreated()
        ->assertJsonPath('stealth_enabled', false)
        ->assertJsonPath('chats_paused', false);
});

it('returns the database defaults for the switches when a car is created directly', function () {
    $this->postJson('/api/v1/cars', carAttrs(), bearer(owner()))
        ->assertCreated()
        ->assertJsonPath('stealth_enabled', false)
        ->assertJsonPath('chats_paused', false);
});

it('answers validation errors in Turkish with translated attribute names', function () {
    $user = owner();
    $car = linkedCar($user);
    $tr = ['Accept-Language' => 'tr'];

    $this->postJson("/api/v1/cars/{$car->id}/contacts", ['label' => 'Anne', 'phone' => '12345'], bearer($user) + $tr)
        ->assertUnprocessable()
        ->assertJsonPath('errors.phone.0', 'telefon alanı biçimi geçersiz.');

    Storage::fake($car->photo_disk ?? config('filesystems.default'));
    $this->post("/api/v1/cars/{$car->id}/photo", ['photo' => UploadedFile::fake()->create('a.txt', 1, 'text/plain')], bearer($user) + $tr + ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.photo.0', 'fotoğraf alanı şu türde bir dosya olmalıdır: image/jpeg, image/png, image/webp.');

    $this->putJson("/api/v1/cars/{$car->id}/status", ['text' => str_repeat('x', 141), 'duration_minutes' => 5], bearer($user) + $tr)
        ->assertUnprocessable()
        ->assertJsonPath('errors.text.0', 'not alanı en fazla 140 karakter olabilir.');
});

it('names the attributes in English too', function () {
    $user = owner();
    $car = linkedCar($user);

    $this->putJson("/api/v1/cars/{$car->id}/status", ['text' => str_repeat('x', 141), 'duration_minutes' => 5], bearer($user) + ['Accept-Language' => 'en'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.text.0', 'The note field must not be greater than 140 characters.');
});
