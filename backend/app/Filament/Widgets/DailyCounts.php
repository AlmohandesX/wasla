<?php

namespace App\Filament\Widgets;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Rows per day for the dashboard charts, counted on the Europe/Istanbul calendar (as the reminder commands are) so
 * a sign-up at 01:00 local time lands on the day the admins think of. Days with no rows are present as zero.
 */
class DailyCounts
{
    public const TIMEZONE = 'Europe/Istanbul';

    /** The brand navy (brand/README.md), the one accent colour in the panel. */
    public const NAVY = '#14213D';

    /**
     * @param  Builder<Model>  $query
     * @return array<string, int> keyed by Y-m-d, oldest first, ending today
     */
    public static function lastDays(Builder $query, string $column, int $days): array
    {
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $first = $today->subDays($days - 1);

        $counts = [];
        for ($day = $first; $day <= $today; $day = $day->addDay()) {
            $counts[$day->format('Y-m-d')] = 0;
        }

        // Only the timestamps are read, and only for the window, so this stays small.
        $query->clone()
            ->where($column, '>=', $first->utc())
            ->toBase()
            ->pluck($column)
            ->each(function ($timestamp) use (&$counts): void {
                $key = CarbonImmutable::parse($timestamp, 'UTC')->setTimezone(self::TIMEZONE)->format('Y-m-d');
                if (isset($counts[$key])) {
                    $counts[$key]++;
                }
            });

        return $counts;
    }

    /** Chart.js options shared by the daily charts: no legend, whole numbers, flat and thinned date labels. */
    public static function chartOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => [
                'x' => ['grid' => ['display' => false], 'ticks' => ['maxRotation' => 0, 'autoSkip' => true, 'maxTicksLimit' => 8]],
                'y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]],
            ],
        ];
    }

    /** Short axis labels such as "9 Nov" for the given Y-m-d keys. */
    public static function labels(array $counts): array
    {
        return array_map(
            fn (string $day): string => CarbonImmutable::parse($day)->locale(app()->getLocale())->translatedFormat('j M'),
            array_keys($counts),
        );
    }
}
