<?php

namespace App\Policies;

use App\Models\Attendance;
use App\Models\ClassSession;
use App\Models\User;

/**
 * Attendance records.
 *
 * Read-only in practice: attendance is written by joining a class session, and
 * staff may review it. A student may read their own record but nobody may edit
 * or delete one — a mark that can be silently changed after the fact is not
 * evidence of anything.
 *
 * This closes a legacy hole: `GET /api/class-sessions/{id}/attendance` had no
 * role check, so any authenticated user could read any roster.
 */
class AttendancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canManageLab();
    }

    public function view(User $user, Attendance $attendance): bool
    {
        return $user->canManageLab() || $attendance->user_id === $user->id;
    }

    /**
     * Attendance rows are created by ClassSession::recordAttendance(), not
     * directly, so this exists only to keep the interface honest.
     */
    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Attendance $attendance): bool
    {
        return false;
    }

    public function delete(User $user, Attendance $attendance): bool
    {
        return false;
    }

    /**
     * Reading a session's roster. Authorised against the session, so it lives on
     * ClassSessionPolicy as `viewAttendance` — Gate resolves an ability from the
     * model it is handed, so `can('viewAttendance', $session)` would never reach
     * this class.
     */
    public function viewAnyForSession(User $user, ClassSession $session): bool
    {
        return $user->canManageLab() || $session->started_by_id === $user->id;
    }
}
