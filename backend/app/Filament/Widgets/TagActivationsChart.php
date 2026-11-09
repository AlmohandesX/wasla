<?php

namespace App\Filament\Widgets;

use App\Models\Tag;
use Filament\Widgets\ChartWidget;

/** Tags activated for the first time per day over the last 30 days. */
class TagActivationsChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '260px';

    public function getHeading(): string
    {
        return __('admin.dashboard.activations_heading');
    }

    public function getDescription(): string
    {
        return __('admin.dashboard.last_30_days');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $counts = DailyCounts::lastDays(Tag::query()->whereNotNull('first_activated_at'), 'first_activated_at', 30);

        return [
            'datasets' => [[
                'label' => __('admin.dashboard.activations'),
                'data' => array_values($counts),
                'backgroundColor' => DailyCounts::NAVY,
                'borderRadius' => 4,
            ]],
            'labels' => DailyCounts::labels($counts),
        ];
    }

    protected function getOptions(): array
    {
        return DailyCounts::chartOptions();
    }
}
