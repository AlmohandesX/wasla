<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ReportedMessageResource;
use App\Models\MessageReport;
use App\Models\Tag;
use App\Models\User;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** The dashboard's headline numbers: owners, activated tags and reports waiting for a moderator. */
class StatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $owners = User::query()->where('role', '!=', 'admin');
        $newOwners = DailyCounts::lastDays($owners, 'created_at', 7);

        $activated = Tag::query()->whereNotNull('first_activated_at');
        $newActivations = DailyCounts::lastDays($activated, 'first_activated_at', 7);

        $openReports = MessageReport::query()->whereNull('reviewed_at')->count();

        return [
            Stat::make(__('admin.dashboard.users'), number_format($owners->count()))
                ->description(__('admin.dashboard.this_week', ['count' => array_sum($newOwners)]))
                ->descriptionIcon(array_sum($newOwners) > 0 ? Heroicon::ArrowTrendingUp : null)
                ->chart(array_values($newOwners))
                ->color(array_sum($newOwners) > 0 ? 'success' : 'gray'),

            Stat::make(__('admin.dashboard.activated_tags'), number_format($activated->count()))
                ->description(__('admin.dashboard.this_week', ['count' => array_sum($newActivations)]))
                ->descriptionIcon(array_sum($newActivations) > 0 ? Heroicon::ArrowTrendingUp : null)
                ->chart(array_values($newActivations))
                ->color(array_sum($newActivations) > 0 ? 'success' : 'gray'),

            Stat::make(__('admin.dashboard.open_reports'), number_format($openReports))
                ->description($openReports > 0 ? __('admin.dashboard.needs_review') : __('admin.dashboard.all_clear'))
                ->descriptionIcon($openReports > 0 ? Heroicon::ExclamationTriangle : Heroicon::CheckCircle)
                ->color($openReports > 0 ? 'danger' : 'success')
                ->url(ReportedMessageResource::getUrl()),
        ];
    }
}
