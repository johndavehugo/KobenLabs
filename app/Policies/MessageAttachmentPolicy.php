<?php

namespace App\Policies;

use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;

/**
 * Message attachments.
 *
 * Inherits visibility from the parent message, so a download can never be a
 * way around the message policy.
 *
 * The legacy API had no attachment download route at all — attachments were
 * listed inline but could not be retrieved. This policy exists to gate the one
 * that still needs to be built.
 */
class MessageAttachmentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, MessageAttachment $attachment): bool
    {
        $message = $attachment->message;

        return $message !== null && app(MessagePolicy::class)->view($user, $message);
    }

    public function create(User $user, Message $message): bool
    {
        return app(MessagePolicy::class)->view($user, $message);
    }

    public function update(User $user, MessageAttachment $attachment): bool
    {
        return false;
    }

    public function delete(User $user, MessageAttachment $attachment): bool
    {
        return app(MessagePolicy::class)->delete($user, $attachment->message);
    }
}
