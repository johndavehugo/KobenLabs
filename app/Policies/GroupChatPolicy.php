<?php

namespace App\Policies;

use App\Enums\GroupRole;
use App\Models\GroupChat;
use App\Models\User;

/**
 * Chat groups.
 *
 * A group is visible to its members. Creating one is staff-only; joining by
 * code is open to anyone, because that is how students get into the groups their
 * teacher created.
 *
 * Note the distinction between the group's `created_by_id` and a member's
 * group-scoped `role` pivot. A student can be a GroupRole::Admin of their own
 * group; that does not make them lab staff.
 */
class GroupChatPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Members, plus staff who need to moderate any group.
     */
    public function view(User $user, GroupChat $group): bool
    {
        return $user->canManageLab() || $group->isMember($user);
    }

    public function create(User $user): bool
    {
        return $user->canManageLab();
    }

    /**
     * Renaming a group, or changing its settings.
     */
    public function update(User $user, GroupChat $group): bool
    {
        return $user->canManageLab()
            || $group->created_by_id === $user->id
            || $group->roleFor($user)?->canManageGroup() === true;
    }

    /**
     * The creator or a lab administrator. Deliberately narrower than the legacy
     * rule, which allowed any member holding the group admin pivot to delete
     * the group outright.
     */
    public function delete(User $user, GroupChat $group): bool
    {
        return $group->created_by_id === $user->id;
    }

    /**
     * Joining by share code. Open to every authenticated user, because that is
     * how students get into the groups their teacher created.
     */
    public function join(User $user, GroupChat $group): bool
    {
        return $group->is_active;
    }

    /**
     * Leaving. The creator cannot leave their own group — there would be nobody
     * left to administer it. Preserved from the legacy behaviour.
     */
    public function leave(User $user, GroupChat $group): bool
    {
        return $group->isMember($user) && $group->created_by_id !== $user->id;
    }

    /**
     * Removing a member, or promoting one to GroupRole::Admin.
     */
    public function manageMembers(User $user, GroupChat $group): bool
    {
        return $user->canManageLab()
            || $group->created_by_id === $user->id
            || $group->roleFor($user) === GroupRole::Admin;
    }
}
