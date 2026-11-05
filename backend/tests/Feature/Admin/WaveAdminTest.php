<?php

use App\Filament\Pages\Settings;
use App\Filament\Resources\WaveResource\Pages\ManageWaves;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\Wave;
use App\Services\Tags\TokenGenerator;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    Filament::setCurrentPanel('admin');
    $this->admin = adminUser();
    $this->actingAs($this->admin);
});

function generatedWave(int $size = 6): Wave
{
    Livewire::test(ManageWaves::class)
        ->callAction(TestAction::make('generate')->table(), ['size' => $size])
        ->assertHasNoActionErrors();

    return Wave::query()->latest('id')->firstOrFail();
}

it('generates a wave and shows pending, sold and activated counts', function () {
    $wave = generatedWave(6);
    expect($wave->status)->toBe('ready')->and($wave->tags()->count())->toBe(6);

    $wave->tags()->orderBy('serial')->limit(2)->update(['sold_at' => now()]);
    $wave->tags()->orderBy('serial')->limit(1)->update(['first_activated_at' => now()]);

    Livewire::test(ManageWaves::class)
        ->assertTableColumnStateSet('pending_count', 4, $wave)
        ->assertTableColumnStateSet('sold_count', 2, $wave)
        ->assertTableColumnStateSet('activated_count', 1, $wave);
});

it('serves the package to admins only', function () {
    $wave = generatedWave(3);
    $url = route('admin.waves.package', $wave);

    $this->get($url)->assertOk()->assertDownload('wave-001.zip');

    auth()->logout();
    app('auth')->forgetGuards();
    $this->get($url)->assertForbidden();
    $this->actingAs(owner(['email_verified_at' => now()]))->get($url)->assertForbidden();
});

it('marks a wave and a serial range sold, and says how many', function () {
    $wave = generatedWave(10);

    Livewire::test(ManageWaves::class)
        ->callAction(TestAction::make('markSerialRangeSold')->table(), ['from' => 'W001-000003', 'to' => 'W001-000005'])
        ->assertNotified(__('admin.waves.range_marked', ['count' => 3]));
    expect($wave->tags()->whereNotNull('sold_at')->count())->toBe(3);

    Livewire::test(ManageWaves::class)
        ->callAction(TestAction::make('markSold')->table($wave))
        ->assertNotified(__('admin.waves.marked', ['count' => 7]));
    expect($wave->tags()->whereNull('sold_at')->count())->toBe(0);
});

it('refuses a malformed or reversed serial range instead of marking zero', function (string $from, string $to, string $key) {
    $wave = generatedWave(5);

    Livewire::test(ManageWaves::class)
        ->callAction(TestAction::make('markSerialRangeSold')->table(), ['from' => $from, 'to' => $to])
        ->assertNotified(__($key));

    expect($wave->tags()->whereNotNull('sold_at')->count())->toBe(0);
})->with([
    'malformed' => ['abc', 'W001-000002', 'admin.serial_invalid'],
    'reversed' => ['W001-000004', 'W001-000002', 'admin.serial_range_order'],
    'two waves' => ['W001-000001', 'W002-000002', 'admin.serial_range_waves'],
]);

it('sorts a range correctly across wave number 1000', function () {
    $wave = Wave::create(['number' => 1000, 'size' => 3, 'status' => 'ready']);
    foreach ([1, 2, 3] as $n) {
        Tag::create(['wave_id' => $wave->id, 'serial' => sprintf('W1000-%06d', $n), 'token' => (new TokenGenerator)->generate()]);
    }
    $other = Wave::create(['number' => 999, 'size' => 1, 'status' => 'ready']);
    $outsider = Tag::create(['wave_id' => $other->id, 'serial' => 'W999-000002', 'token' => (new TokenGenerator)->generate()]);

    Livewire::test(ManageWaves::class)
        ->callAction(TestAction::make('markSerialRangeSold')->table(), ['from' => 'W1000-000001', 'to' => 'W1000-000002'])
        ->assertNotified(__('admin.waves.range_marked', ['count' => 2]));

    expect($outsider->fresh()->sold_at)->toBeNull();
});

it('sets the renewal price, as an integer in kurus', function () {
    Livewire::test(Settings::class)
        ->set('data.price', '799,90')
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::value('renewal_price_kurus'))->toBe('79990');

    Livewire::test(Settings::class)->assertSet('data.price', '799,90')
        ->set('data.price', '800')->call('save');
    expect(Setting::value('renewal_price_kurus'))->toBe('80000');
});

it('rejects a negative, zero, over-precise or oversized price', function (string $price) {
    Setting::create(['key' => 'renewal_price_kurus', 'value' => '79900']);

    Livewire::test(Settings::class)->set('data.price', $price)->call('save')->assertHasErrors(['data.price']);

    expect(Setting::value('renewal_price_kurus'))->toBe('79900');
})->with(['-5', '0', '0,00', '12.345', '1e3', 'abc', '99999999999']);
