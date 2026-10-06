<?php

namespace App\Filament\Student\Widgets;

use App\Filament\Student\Pages\MyDrive;
use App\Models\User;
use App\Support\Bytes;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The student's own storage, as used against their quota.
 *
 * Quota is enforced in one place (`FileService::assertWithinQuota`) and covers
 * message and group chat attachments as well as drive files, which the legacy
 * app did not — so the number here is the number the upload guard will use.
 */
class MyStorage extends StatsOverviewWidget
{
    protected ?string $heading = 'My storage';

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 1;

    public static function canView(): bool
    {
        return auth()->user()?->isStudent() ?? false;
    }

    protected function getStats(): array
    {
        $user = $this->user();

        $quota = $user->storageQuotaBytes();
        $used = $user->storageUsedBytes();

        // Reused rather than reading storage_used_mb, which is a cached counter;
        // this widget is the one place that should be able to disagree with it.
        $percentage = $quota > 0 ? min(100, round(($used / $quota) * 100)) : 0;

        return [
            Stat::make('Used', Bytes::format($used))
                ->description($quota > 0
                    ? $percentage.'% of '.Bytes::format($quota)
                    : 'No quota set')
                ->descriptionIcon('heroicon-m-server')
                ->color($percentage >= 90 ? 'danger' : ($percentage >= 70 ? 'warning' : 'success'))
                ->url(MyDrive::getUrl(panel: 'student')),

            Stat::make('Remaining', $quota > 0 ? Bytes::format(max(0, $quota - $used)) : 'Unlimited')
                ->description('Includes message attachments')
                ->color('gray')
                ->url(MyDrive::getUrl(panel: 'student')),
        ];
    }

    private function user(): User
    {
        return auth()->user();
    }
}
