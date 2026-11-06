<?php

use App\Models\EmergencyContact;
use App\Services\Moderation\ModerationPipeline;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config(['wasla.moderation.api_key' => 'sk-test', 'wasla.moderation.daily_cap' => 5000]);
    Cache::flush();
    Http::fake(['api.openai.com/*' => Http::response(['results' => [['category_scores' => ['harassment' => 0.01]]]])]);
});

it('refuses owner text past the per-owner daily moderation budget without calling the provider', function () {
    config(['wasla.moderation.owner_daily_cap' => 3]);
    $user = owner();
    $car = linkedCar($user);
    $headers = bearer($user);

    foreach (range(1, 3) as $i) {
        $this->patchJson("/api/v1/cars/{$car->id}", ['default_message' => "Merhaba $i"], $headers)->assertOk();
    }
    $this->patchJson("/api/v1/cars/{$car->id}", ['default_message' => 'Merhaba 4'], $headers)
        ->assertStatus(429)->assertJsonPath('code', 'moderation_limit')
        ->assertJsonPath('message', __('wasla.error_moderation_limit'));

    expect($car->refresh()->default_message)->toBe('Merhaba 3');
    Http::assertSentCount(3);

    // Another owner is unaffected, and the budget comes back the next Istanbul day.
    $other = owner();
    $theirs = bearer($other);
    $mine = bearer($user);
    app('auth')->forgetGuards();
    $this->patchJson('/api/v1/cars/'.linkedCar($other)->id, ['default_message' => 'Selam'], $theirs)->assertOk();
    app('auth')->forgetGuards();
    $this->travelTo(now('Europe/Istanbul')->addDay()->startOfDay()->addHour());
    $this->patchJson("/api/v1/cars/{$car->id}", ['default_message' => 'Yeni gun'], $mine)->assertOk();
});

it('caps emergency contacts at five per car', function () {
    $user = owner();
    $car = linkedCar($user);
    foreach (range(1, 5) as $i) {
        EmergencyContact::create(['car_id' => $car->id, 'label' => "Kisi $i", 'phone' => '+90532123456'.$i, 'position' => $i]);
    }

    $this->postJson("/api/v1/cars/{$car->id}/contacts", ['label' => 'Altinci', 'phone' => '05321234560'], bearer($user))
        ->assertUnprocessable()->assertJsonValidationErrors(['contacts']);

    expect($car->contacts()->count())->toBe(5);
    Http::assertNothingSent();
});

it('alerts admins once in the log when the platform daily cap is reached', function () {
    config(['wasla.moderation.daily_cap' => 2]);
    Log::spy();
    $pipeline = app(ModerationPipeline::class);

    foreach (range(1, 5) as $i) {
        $pipeline->check("metin $i", ['surface' => 'chat']);
    }

    Log::shouldHaveReceived('error')->withArgs(fn ($m, $c = []) => str_contains($m, 'daily cap') && $c === ['cap' => 2])->once();
});
