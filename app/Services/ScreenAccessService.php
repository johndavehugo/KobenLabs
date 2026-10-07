<?php

namespace App\Services;

use App\Enums\ClassSessionStatus;
use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\ClassSession;
use App\Models\Pc;
use App\Models\PcSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who may see which PC's screen.
 *
 * This exists as one service rather than as logic in the policy and in the page
 * because the two must not be able to disagree. Legacy's equivalent rule lived in
 * the browser: its WebSocket relay sent every frame to every connected teacher, and
 * the grid then dropped the ones that did not belong to the current class. That
 * filtering was cosmetic — the frames had already been transmitted and decoded. A
 * rule that only holds in the UI is not a control.
 *
 * Both the Live Screens grid and the frame endpoint call this, so a teacher is shown
 * exactly the machines they are permitted to fetch. There is no case where the grid
 * offers a PC that the server would then refuse.
 *
 * The rule itself:
 *
 *   - Students never. Lab equipment being institution-owned does not make student
 *     screens readable by students, and no student should be able to read another's.
 *   - Admins always, so an administrator can deal with a machine in any class.
 *   - Teachers only for the class they are running, which is what
 *     ClassSessionPolicy::viewAttendance already does for the roster.
 */
class ScreenAccessService
{
    /**
     * PCs whose screens this user may watch.
     *
     * @return Collection<int, Pc>
     */
    public function viewablePcs(User $user): Collection
    {
        if (! $user->canManageLab()) {
            return collect();
        }

        if ($user->role === UserRole::Admin) {
            // Eager-loads activeSessions so the grid can label who is sitting at each
            // machine without an N+1 across the tiles.
            return Pc::query()
                ->with(['activeSessions.user', 'client'])
                ->orderBy('name')
                ->get();
        }

        $sessions = $this->activeSessionsFor($user);

        if ($sessions->isEmpty()) {
            return collect();
        }

        $studentIds = Attendance::query()
            ->whereIn('class_session_id', $sessions->pluck('id'))
            ->select('user_id');

        return Pc::query()
            ->whereIn('id', PcSession::query()
                ->active()
                ->whereIn('user_id', $studentIds)
                ->select('pc_id'))
            ->with(['activeSessions.user', 'client'])
            ->orderBy('name')
            ->get();
    }

    /**
     * Whether this user may fetch this PC's frame. The authoritative check.
     */
    public function mayView(User $user, Pc $pc): bool
    {
        if (! $user->canManageLab()) {
            return false;
        }

        if ($user->role === UserRole::Admin) {
            return true;
        }

        $sessions = $this->activeSessionsFor($user);

        if ($sessions->isEmpty()) {
            return false;
        }

        $studentIds = Attendance::query()
            ->whereIn('class_session_id', $sessions->pluck('id'))
            ->select('user_id');

        return PcSession::query()
            ->active()
            ->where('pc_id', $pc->getKey())
            ->whereIn('user_id', $studentIds)
            ->exists();
    }

    /**
     * Active sessions this user is running.
     *
     * Note this is scoped by teacher, unlike ClassSessionService::active() which
     * returns the one session globally — that is only equivalent while
     * `lab.class_sessions.single_active_session` is true, which is not something
     * screen access should depend on.
     *
     * @return Collection<int, ClassSession>
     */
    private function activeSessionsFor(User $user): Collection
    {
        return ClassSession::query()
            ->where('status', ClassSessionStatus::Active)
            ->where('started_by_id', $user->getKey())
            ->get(['id']);
    }

    /**
     * Exposed for the Live Screens page so it can label a tile with the session it
     * belongs to without a second traversal of the roster.
     *
     * @return Builder<ClassSession>
     */
    public function sessionsFor(User $user): Builder
    {
        return ClassSession::query()
            ->where('started_by_id', $user->getKey())
            ->where('status', ClassSessionStatus::Active);
    }
}
