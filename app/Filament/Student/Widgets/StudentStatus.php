<?php

namespace App\Filament\Student\Widgets;

use App\Filament\Student\Pages\JoinClass;
use App\Filament\Student\Pages\Messages as StudentMessages;
use App\Models\ClassSession;
use App\Models\Message;
use App\Models\User;
use App\Services\ClassSessionService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Attributes\On;

/**
 * What needs the student's attention: unread mail, and whether a class is running.
 *
 * Legacy's student dashboard (`user/views/dashboard.js`) showed an unread message
 * count and drive usage, then redirected to the class view if a session was
 * active. Same three facts, computed server-side.
 */
class StudentStatus extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->isStudent() ?? false;
    }

    protected function getStats(): array
    {
        $user = $this->user();
        $session = app(ClassSessionService::class)->active();
        $unread = $this->unreadCount($user);

        return [
            Stat::make('Unread messages', $unread)
                ->description($unread === 1 ? '1 waiting' : $unread.' waiting')
                ->descriptionIcon('heroicon-m-envelope')
                ->color($unread > 0 ? 'warning' : 'gray')
                ->url(StudentMessages::getUrl(panel: 'student')),

            Stat::make(
                $session === null ? 'No class running' : 'A class is running',
                $session === null ? '—' : (string) $session->schedule?->title,
            )
                ->description($session === null
                    ? 'Nothing to join right now'
                    : ($this->hasJoined($session, $user)
                        ? 'You have joined this class'
                        : 'Press Join class to record your attendance'))
                ->descriptionIcon($session === null ? 'heroicon-m-moon' : 'heroicon-m-academic-cap')
                ->color($session === null ? 'gray' : 'success')
                ->url(JoinClass::getUrl(panel: 'student')),
        ];
    }

    /**
     * The stat's whole purpose is answering "is a class running right now?", which is
     * exactly the question a broadcast can answer. Without this the dashboard kept
     * saying "No class running" until the student reloaded.
     */
    #[On('koben:class-session-started')]
    #[On('koben:class-session-ended')]
    public function refreshRunningClassStat(): void
    {
        $this->dispatch('$refresh');
    }

    /**
     * Same predicate as the inbox, via the model's scope, so the badge and the
     * list cannot disagree.
     */
    private function unreadCount(User $user): int
    {
        return Message::query()
            ->visibleTo($user)
            ->unreadBy($user)
            ->count();
    }

    /**
     * Whether this student already has an attendance row, using the same relation
     * JoinClass checks so the widget and the page never disagree.
     */
    private function hasJoined(?ClassSession $session, User $user): bool
    {
        return $session !== null
            && $session->attendance()->where('user_id', $user->id)->exists();
    }

    private function user(): User
    {
        return auth()->user();
    }
}
