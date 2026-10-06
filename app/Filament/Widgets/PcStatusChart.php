<?php

namespace App\Filament\Widgets;

use App\Enums\PcStatus;
use App\Models\Pc;
use Filament\Widgets\DoughnutChartWidget;

/**
 * PC inventory as a doughnut, matching the legacy admin dashboard's chart.
 *
 * Maintenance is shown as its own slice rather than folded into offline, because
 * `PcStatus::isAutoOfflinable()` treats them differently: the stale sweep skips
 * maintenance PCs, so lumping them in would make the chart argue with the
 * scheduler.
 */
class PcStatusChart extends DoughnutChartWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 1;

    public static function canView(): bool
    {
        return auth()->user()?->canManageLab() ?? false;
    }

    protected function getData(): array
    {
        $counts = Pc::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'datasets' => [[
                'data' => array_map('intval', [
                    PcStatus::Online->value => $counts[PcStatus::Online->value] ?? 0,
                    PcStatus::Offline->value => $counts[PcStatus::Offline->value] ?? 0,
                    PcStatus::Maintenance->value => $counts[PcStatus::Maintenance->value] ?? 0,
                ]),
                'backgroundColor' => [
                    PcStatus::Online->color(),
                    PcStatus::Offline->color(),
                    PcStatus::Maintenance->color(),
                ],
                'borderWidth' => 0,
            ]],
            'labels' => [
                PcStatus::Online->label(),
                PcStatus::Offline->label(),
                PcStatus::Maintenance->label(),
            ],
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    public function getHeading(): string
    {
        $total = Pc::query()->count();

        return 'PC status ('.$total.')';
    }
}
