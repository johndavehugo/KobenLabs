<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Schedule;
use App\Models\User;

/**
 * Class schedules.
 *
 * Everyone reads the timetable. Only staff may book or change a reservation.
 *
 * The legacy UI enforced "teachers view, admins edit" in JavaScript
 * (`schedule.js` checked `user.role === 'admin'` to hide the add and delete
 * affordances), which is not a security control. The server applied the same
 * restriction via a `requireTeacherRole()` helper, so both teachers and
 * administrators are staff here.
 */
class SchedulePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Schedule $schedule): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->canManageLab();
    }

    public function update(User $user, Schedule $schedule): bool
    {
        return $user->canManageLab();
    }

    public function delete(User $user, Schedule $schedule): bool
    {
        return $user->canManageLab();
    }

    /**
     * Gates the bulk delete action on the table.
     */
    public function deleteAny(User $user): bool
    {
        return $user->canManageLab();
    }

    /**
     * A teacher may only modify their own bookings; an administrator may
     * modify any. Cancelling someone else's class is a management action.
     */
    public function cancel(User $user, Schedule $schedule): bool
    {
        return $user->canManageLab() && ($user->role === UserRole::Admin || $schedule->teacher_id === $user->id);
    }
}
