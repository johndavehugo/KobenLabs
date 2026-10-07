<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Somebody is typing in a group.
 *
 * Broadcast only, and never stored. That is the whole design: a typing indicator is a
 * momentary fact about the present, so writing it to the database would grow a table with
 * a row per keystroke burst and give it no reader. It is also the one message a client can
 * receive that is not in the transcript, which is why the payload carries the author's
 * name - the browser has to render "Maria is typing" from this event alone.
 *
 * ShouldBroadcastNow rather than ShouldBroadcast. A queued broadcast adds the latency of a
 * worker round trip to something whose entire useful life is a couple of seconds; by the
 * time it arrived the typist would usually have finished, so it would be stale on arrival.
 * It also must not be able to fail the request that triggered it, which is why sending is
 * wrapped by the caller.
 *
 * Authorization is the existing `groups.{groupId}` channel, so this cannot announce a
 * group the sender is not in - there is no separate check here to forget.
 */
class GroupChatTyping implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  int  $groupId  A plain id rather than the model, deliberately: the payload
     *                        must not carry a serialized model, since the only thing a
     *                        subscriber needs is who is typing and where.
     */
    public function __construct(
        public readonly int $groupId,
        public readonly int $userId,
        public readonly string $userName,
    ) {}

    /**
     * @return array<int, string>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('groups.'.$this->groupId),
        ];
    }

    /**
     * The name rather than an id, and no message body.
     *
     * The event is visible to everyone in the room, so the payload is kept to the one
     * string a browser has to render. The user's own events come back to them too, so the
     * client filters on `user_id` rather than the server trying to exclude one recipient.
     */
    public function broadcastAs(): string
    {
        return 'group-chat.typing';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'group_id' => $this->groupId,
            'user_id' => $this->userId,
            'user_name' => $this->userName,
        ];
    }
}
