<?php

namespace App\Filament\Student\Widgets;

use App\Filament\Student\Pages\ChatRoom as StudentChatRoom;
use App\Filament\Student\Pages\JoinClass;
use App\Models\ClassSession;
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
        $groups = $this->groupCount($user);

        return [
            Stat::make('Group chats', $groups)
                ->description($groups === 1 ? '1 joined' : $groups.' joined')
                ->descriptionIcon('heroicon-m-chat-bubble-left-right')
                ->color($groups > 0 ? 'success' : 'gray')
                ->url(StudentChatRoom::getUrl(panel: 'student')),

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
     * How many group chats this student is in.
     *
     * Replaced the unread-message badge. Group chat has no equivalent of an unread
     * count — `group_message_reads` exists but nothing surfaces it, and a per-message
     * read receipt in a room that is meant to be visible to everyone is close to
     * meaningless. What a student actually wants to know is which rooms they are in.
     */
    private function groupCount(User $user): int
    {
        return $user->groupChats()->where('is_active', true)->count();
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
