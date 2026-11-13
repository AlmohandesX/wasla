<?php

namespace App\Filament\Widgets;

use App\Models\User;
use Filament\Widgets\ChartWidget;

/** New owner accounts per day over the last 30 days. */
class UserRegistrationsChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '260px';

    public function getHeading(): string
    {
        return __('admin.dashboard.registrations_heading');
    }

    public function getDescription(): string
    {
        return __('admin.dashboard.last_30_days');
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $counts = DailyCounts::lastDays(User::query()->where('role', '!=', 'admin'), 'created_at', 30);

        return [
            'datasets' => [[
                'label' => __('admin.dashboard.new_users'),
                'data' => array_values($counts),
                'borderColor' => DailyCounts::NAVY,
                'backgroundColor' => 'rgba(20, 33, 61, 0.08)',
                'pointBackgroundColor' => DailyCounts::NAVY,
                'fill' => true,
                'tension' => 0.3,
            ]],
            'labels' => DailyCounts::labels($counts),
        ];
    }

    protected function getOptions(): array
    {
        return DailyCounts::chartOptions();
    }
}
