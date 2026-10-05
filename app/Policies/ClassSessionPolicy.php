<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\ClassSession;
use App\Models\User;

/**
 * Live teaching sessions.
 *
 * Only staff start and end a session. Students may join one — that is the
 * action that writes their attendance record — and may read the active session
 * so they can discover it in the first place.
 */
class ClassSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ClassSession $session): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->canManageLab();
    }

    /**
     * Ending a session. Scoped to the teacher who started it, or staff at large.
     */
    public function update(User $user, ClassSession $session): bool
    {
        return $user->canManageLab() && ($user->role === UserRole::Admin || $session->started_by_id === $user->id);
    }

    public function delete(User $user, ClassSession $session): bool
    {
        return $user->role === UserRole::Admin;
    }

    /**
     * Gates the bulk delete action on the table. History is evidence, so this
     * mirrors `delete` and stays administrator-only.
     */
    public function deleteAny(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    /**
     * Joining a session, which records attendance.
     *
     * Available to every authenticated user, including staff — a teacher
     * demonstrating the flow needs to see the same button a student does.
     */
    public function join(User $user, ClassSession $session): bool
    {
        return $session->isActive();
    }

    /**
     * Reading the attendance roster for a session.
     *
     * Lives here rather than on AttendancePolicy because it is authorised against
     * the session, and Gate resolves an ability from the model it is passed.
     *
     * Narrower than `canManageLab()` on purpose: a teacher sees the rosters of
     * classes they started, mirroring the rule that governs ending one. Granting
     * every teacher every roster would mean one teacher can read who was present
     * in another teacher's class, which the legacy app allowed for *everyone*
     * because the endpoint had no role check at all.
     */
    public function viewAttendance(User $user, ClassSession $session): bool
    {
        return $user->role === UserRole::Admin || $session->started_by_id === $user->id;
    }
}
