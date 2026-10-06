<?php

namespace App\Events;

use App\Models\GroupChat;
use App\Models\GroupChatMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A message was posted to a group chat.
 *
 * Carries only what a client needs to append a row. The body is included because
 * it is already visible to every member of the channel — that is what membership
 * means — but nothing about who else is in the group is sent.
 *
 * `ShouldBroadcastNow` rather than `ShouldBroadcast`, for the same reason as
 * `PcStatusUpdated`: delivery must not depend on a worker being alive, because the
 * one that mattered in this app was the PC grid updating behind a teacher's back.
 */
class GroupChatMessageSent implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public GroupChatMessage $message,
        public GroupChat $group,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        // Per-group channel rather than one global channel: the group id is
        // carried in the name so routes/channels.php can authorize against
        // GroupChatPolicy, and a client can never subscribe to a group it is not
        // in.
        return [new PrivateChannel('groups.'.$this->group->getKey())];
    }

    public function broadcastAs(): string
    {
        return 'group-chat.message.sent';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $message = $this->message;

        return [
            'id' => $message->getKey(),
            'group_id' => $this->group->getKey(),
            'sender_id' => $message->sender_id,
            'sender_name' => $message->sender?->full_name,
            'body' => $message->body,
            'type' => $message->type?->value,
            'file_name' => $message->hasAttachment() ? $message->file_name : null,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }
}
