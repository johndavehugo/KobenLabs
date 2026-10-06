<?php

namespace App\Filament\Widgets;

use App\Enums\PcStatus;
use App\Filament\Resources\ClassSessions\ClassSessionResource;
use App\Filament\Resources\Pcs\PcResource;
use App\Filament\Resources\Schedules\ScheduleResource;
use App\Models\ClassSession;
use App\Models\Pc;
use App\Models\User;
use App\Services\ClassSessionService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The admin dashboard's headline numbers.
 *
 * Legacy's admin dashboard (`admin/views/dashboard.js`) showed PC counts, the
 * running class and how full the lab was, but derived them client-side from
 * whichever API calls the page happened to make first. Here they are one place.
 */
class LabOverview extends StatsOverviewWidget
{
    protected ?string $heading = 'Lab';

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    /**
     * PC inventory is not a student's business. The student panel has its own
     * widgets, and `canManageLab()` keeps this one off a student's dashboard
     * even though the admin panel itself is unreachable for them.
     */
    public static function canView(): bool
    {
        return auth()->user()?->canManageLab() ?? false;
    }

    protected function getStats(): array
    {
        $pcs = Pc::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $online = (int) ($pcs[PcStatus::Online->value] ?? 0);
        $offline = (int) ($pcs[PcStatus::Offline->value] ?? 0);
        $maintenance = (int) ($pcs[PcStatus::Maintenance->value] ?? 0);
        $total = $online + $offline + $maintenance;

        $students = User::query()->students()->count();

        // Resolved once: this widget runs on every dashboard load, and calling
        // active() per stat meant a query per figure.
        $session = app(ClassSessionService::class)->active();

        return [
            Stat::make('PCs online', $online.' / '.$total)
                ->description($total > 0
                    ? round(($online / $total) * 100).'% of inventory'
                    : 'No PCs registered')
                ->descriptionIcon('heroicon-m-signal')
                ->color($online > 0 ? 'success' : 'gray')
                ->url(PcResource::getUrl('index')),

            Stat::make('PCs offline', $offline)
                ->description($total > 0
                    ? round(($offline / $total) * 100).'% of inventory'
                    : 'No PCs registered')
                ->color($offline > 0 ? 'warning' : 'success')
                ->url(PcResource::getUrl('index')),

            // Its own card rather than folded into offline, because the stale
            // sweep skips maintenance machines: PcStatus::isAutoOfflinable().
            Stat::make('In maintenance', $maintenance)
                ->description($maintenance > 0 ? 'Not swept as stale' : 'None flagged')
                ->descriptionIcon('heroicon-m-wrench-screwdriver')
                ->color($maintenance > 0 ? 'danger' : 'success')
                ->url(PcResource::getUrl('index')),

            Stat::make('Total inventory', $total)
                ->description('Across all statuses')
                ->color('gray')
                ->url(PcResource::getUrl('index')),

            Stat::make('Students present', $this->presentCount($session).' / '.$students)
                ->description('On the roster of the running class')
                ->color('info'),

            $this->classStat($session),
        ];
    }

    /**
     * Students holding an attendance row on the running session.
     */
    private function presentCount(?ClassSession $session): int
    {
        return $session?->attendance()->count() ?? 0;
    }

    private function classStat(?ClassSession $session): Stat
    {
        if ($session === null) {
            return Stat::make('Class in progress', 'None')
                ->description('Start one from a schedule')
                ->color('gray')
                ->url(ScheduleResource::getUrl('index'));
        }

        return Stat::make('Class in progress', (string) $session->schedule?->title)
            ->description('Started by '.($session->startedBy?->full_name ?? 'someone'))
            ->color('success')
            ->url(ClassSessionResource::getUrl('view', ['record' => $session]));
    }
}
