<?php

namespace App\Events;

use App\Models\ClassSession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A class session went live.
 *
 * The legacy server sent `class_session_started` to *every* connected socket,
 * teachers and students alike. That is kept here, but the channel is private —
 * the legacy WebSocket had no authentication at all, so anyone could connect,
 * register as a teacher, and watch the whole lab.
 */
class ClassSessionStarted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $classSessionId,
        public readonly string $subject,
        public readonly string $teacherName,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('class-sessions'),
            new PrivateChannel('pcs'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'class-session.started';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'class_session_id' => $this->classSessionId,
            'subject' => $this->subject,
            'teacher_name' => $this->teacherName,
        ];
    }

    public static function forSession(ClassSession $session): self
    {
        return new self(
            $session->id,
            $session->schedule?->title ?? 'Class',
            $session->startedBy?->full_name ?? 'Unknown',
        );
    }
}
