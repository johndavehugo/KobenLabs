<?php

namespace App\Policies;

use App\Enums\GroupRole;
use App\Models\GroupChatMessage;
use App\Models\User;

/**
 * Group chat messages.
 *
 * Reachable only by members of the owning group. The legacy membership check
 * was duplicated as an inline `foreach` in five separate controller methods,
 * and `download-group-file.php` omitted it entirely — meaning any logged-in user
 * could download any group's attachments by passing a path.
 */
class GroupChatMessagePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, GroupChatMessage $message): bool
    {
        $group = $message->groupChat;

        return $group !== null && app(GroupChatPolicy::class)->view($user, $group);
    }

    public function create(User $user, GroupChatMessage $message): bool
    {
        $group = $message->groupChat;

        return $group !== null && app(GroupChatPolicy::class)->view($user, $group);
    }

    public function update(User $user, GroupChatMessage $message): bool
    {
        // Messages are immutable once sent. This closes a hole rather than
        // opening a capability.
        return false;
    }

    /**
     * The sender, a group administrator, or lab staff.
     *
     * System messages have no sender, so `sender_id === $user->id` is false for
     * them and only a group administrator or staff can remove one.
     */
    public function delete(User $user, GroupChatMessage $message): bool
    {
        if ($user->canManageLab() || $message->groupChat?->roleFor($user) === GroupRole::Admin) {
            return true;
        }

        return $message->sender_id === $user->id;
    }

    /**
     * Downloading an attachment sent to the group.
     */
    public function download(User $user, GroupChatMessage $message): bool
    {
        return $this->view($user, $message);
    }
}
