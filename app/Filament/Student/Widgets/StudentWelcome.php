<?php

namespace App\Filament\Student\Widgets;

use App\Filament\Student\Pages\ChatRoom;
use App\Filament\Student\Pages\JoinClass;
use App\Filament\Student\Pages\MyAttendance;
use App\Filament\Student\Pages\MyDrive;
use App\Models\StoredFile;
use App\Models\User;
use App\Support\Bytes;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * A greeting, the links a student actually uses, and what changed recently.
 *
 * The legacy student dashboard did the same three things client-side by stitching
 * together whatever the API returned first. Here they are one server-rendered
 * component, so the page is useful on first paint rather than after three fetches.
 */
class StudentWelcome extends Widget
{
    /**
     * @var view-string
     */
    protected string $view = 'filament.student.widgets.student-welcome';

    protected static string|BackedEnum|null $icon = 'heroicon-o-sparkles';

    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->isStudent() ?? false;
    }

    /**
     * The view's data.
     *
     * Named getViewData() because Widget::render() calls exactly that: an earlier
     * viewData() looked right and rendered an empty page instead of erroring, which
     * is a quiet way to ship a blank dashboard.
     *
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = auth()->user();

        return [
            'greeting' => $this->greeting(),
            'name' => $user->first_name,
            'storage' => $this->storageSummary($user),
            'actions' => $this->quickActions(),
            'activity' => $this->recentActivity($user),
        ];
    }

    /**
     * Time of day, from the server's clock.
     *
     * Rendered server-side rather than in JS so it is right on first paint and
     * readable by assistive technology without a live region.
     */
    private function greeting(): string
    {
        return match ((int) now()->format('G')) {
            0, 1, 2, 3, 4, 5 => 'Good morning',
            6, 7, 8, 9, 10, 11 => 'Good morning',
            12, 13, 14, 15, 16, 17 => 'Good afternoon',
            default => 'Good evening',
        };
    }

    private function storageSummary(User $user): string
    {
        $quota = $user->storageQuotaBytes();

        if ($quota <= 0) {
            return Bytes::format($user->storageUsedBytes()).' used';
        }

        return sprintf(
            '%s of %s used',
            Bytes::format($user->storageUsedBytes()),
            Bytes::format($quota),
        );
    }

    /**
     * @return array<int, array{label: string, url: string, icon: string}>
     */
    private function quickActions(): array
    {
        return [
            ['label' => 'Join class', 'url' => JoinClass::getUrl(panel: 'student'), 'icon' => 'heroicon-o-academic-cap'],
            ['label' => 'Group Chat', 'url' => ChatRoom::getUrl(panel: 'student'), 'icon' => 'heroicon-o-chat-bubble-left-right'],
            ['label' => 'My Drive', 'url' => MyDrive::getUrl(panel: 'student'), 'icon' => 'heroicon-o-folder'],
            ['label' => 'My Attendance', 'url' => MyAttendance::getUrl(panel: 'student'), 'icon' => 'heroicon-o-calendar-days'],
        ];
    }

    /**
     * The student's own uploads, newest first.
     *
     * Was files merged with direct messages. Messages are gone, so this is one source
     * again — and there is nothing to merge it with, which is the point: a dashboard that
     * showed recent messages from teachers sat one click away from showing everything
     * else a student had received, on a machine that may be shared.
     *
     * @return Collection<int, array{description: string}>
     */
    private function recentActivity(User $user, int $limit = 8): Collection
    {
        return $user->files()
            ->files()
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (StoredFile $file): array => [
                'kind' => 'file',
                'label' => $file->name,
                'detail' => Bytes::format((int) $file->size).' uploaded',
                'when' => $file->created_at,
            ])
            ->sortByDesc('when')
            ->take($limit)
            ->values();
    }
}
