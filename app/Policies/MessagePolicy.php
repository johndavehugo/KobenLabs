<?php

namespace App\Policies;

use App\Models\Message;
use App\Models\User;

/**
 * Direct messages.
 *
 * Visible only to the sender and the recipients — administrators included. A
 * private message between two teachers is not lab business, so no override is
 * defined. This closes a legacy hole: `GET /api/messages/{id}` performed no
 * recipient check, so any authenticated user could read any message by guessing
 * an id.
 *
 * Note that this table backs a feature the legacy UI never actually surfaced —
 * see docs/migration/README.md §7.
 */
class MessagePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Message $message): bool
    {
        return $message->sender_id === $user->id
            || $message->recipients()->whereKey($user->id)->exists();
    }

    /**
     * Only staff originate messages. A reply is still a new message, so the
     * sender check belongs here rather than on update.
     */
    public function create(User $user): bool
    {
        return $user->canManageLab();
    }

    public function update(User $user, Message $message): bool
    {
        return false;
    }

    /**
     * Sender or staff. The legacy rule was "sender or admin", which left
     * teachers unable to remove their own mis-sent broadcast.
     */
    public function delete(User $user, Message $message): bool
    {
        return $message->sender_id === $user->id;
    }

    /**
     * Broadcasting to every student.
     */
    public function broadcast(User $user, Message $message): bool
    {
        return $user->canManageLab();
    }
}
