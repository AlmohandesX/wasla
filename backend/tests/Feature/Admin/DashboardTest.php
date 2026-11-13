<?php

use App\Filament\Widgets\DailyCounts;
use App\Filament\Widgets\StatsOverview;
use App\Filament\Widgets\TagActivationsChart;
use App\Filament\Widgets\UserRegistrationsChart;
use App\Models\MessageReport;
use App\Models\User;
use App\Services\Chat\MessageReportService;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    // 12:00 in Istanbul (UTC+3).
    $this->travelTo(now()->parse('2026-11-09 09:00:00', 'UTC'));
    $this->actingAs(adminUser());
});

function ownerCreatedAt(string $utc): User
{
    $user = owner();
    $user->forceFill(['created_at' => now()->parse($utc, 'UTC')])->save();

    return $user;
}

it('shows the three widgets on the dashboard', function () {
    $this->get('/admin')
        ->assertOk()
        ->assertSeeLivewire(StatsOverview::class)
        ->assertSeeLivewire(UserRegistrationsChart::class)
        ->assertSeeLivewire(TagActivationsChart::class);
});

it('counts per Istanbul day, fills empty days with zero and ignores rows before the window', function () {
    ownerCreatedAt('2026-11-08 22:30:00'); // 01:30 on 9 Nov in Istanbul
    ownerCreatedAt('2026-11-08 20:00:00'); // 23:00 on 8 Nov in Istanbul
    ownerCreatedAt('2026-11-02 21:00:00'); // 00:00 on 3 Nov: the first day of a 7-day window
    ownerCreatedAt('2026-11-02 20:59:59'); // just before it

    $counts = DailyCounts::lastDays(User::query()->where('role', '!=', 'admin'), 'created_at', 7);

    expect($counts)->toBe([
        '2026-11-03' => 1, '2026-11-04' => 0, '2026-11-05' => 0, '2026-11-06' => 0,
        '2026-11-07' => 0, '2026-11-08' => 1, '2026-11-09' => 1,
    ]);
    expect(DailyCounts::lastDays(User::query(), 'created_at', 30))->toHaveCount(30);
});

it('shows owner, activated-tag and open-report totals with the last 7 days', function () {
    ownerCreatedAt('2026-11-08 10:00:00');
    ownerCreatedAt('2026-10-01 10:00:00');
    $car = linkedCar(ownerCreatedAt('2026-09-01 10:00:00')); // activated yesterday
    tagFixture(); // never activated
    tagFixture(['first_activated_at' => now()->subDays(20)]);

    $thread = chatThread($car, 'you are an idiot');
    $reports = app(MessageReportService::class);
    $reports->reportByOwner($thread->messages()->first(), $car->user);

    Livewire::test(StatsOverview::class)
        ->assertSeeInOrder(['Registered users', '3', '+1 in the last 7 days'])
        ->assertSeeInOrder(['Activated tags', '2', '+1 in the last 7 days'])
        ->assertSeeInOrder(['Open reports', '1', 'Waiting for review']);

    MessageReport::query()->update(['reviewed_at' => now()]);

    Livewire::test(StatsOverview::class)->assertSeeInOrder(['Open reports', '0', 'Nothing to review']);
});

it('charts 30 days of registrations and activations', function () {
    ownerCreatedAt('2026-11-09 08:00:00');
    linkedCar(ownerCreatedAt('2026-11-09 08:30:00'));

    foreach ([UserRegistrationsChart::class => 2, TagActivationsChart::class => 0] as $widget => $today) {
        $data = (fn () => $this->getData())->call(new $widget);
        expect($data['labels'])->toHaveCount(30)->and($data['labels'][29])->toBe('9 Nov')
            ->and($data['datasets'][0]['data'])->toHaveCount(30)
            ->and($data['datasets'][0]['data'][29])->toBe($today);

        Livewire::test($widget)->assertOk();
    }

    // linkedCar activates its tag a day back, so it lands on 8 Nov.
    $activations = (fn () => $this->getData())->call(new TagActivationsChart);
    expect($activations['datasets'][0]['data'][28])->toBe(1);
});
