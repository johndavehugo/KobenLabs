<?php

namespace App\Events;

use App\Models\ClassSession;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A student joined a running class, so the attendance roster updates.
 *
 * Sent to the teaching channel only. The legacy server also pushed this to
 * students — but no student-side code ever subscribed, so the payload went
 * nowhere. Sending it only where it is consumed avoids implying a live student
 * experience that does not exist yet.
 */
class StudentJoinedClass implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $classSessionId,
        public readonly string $studentName,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('class-sessions')];
    }

    public function broadcastAs(): string
    {
        return 'student.joined-class';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'class_session_id' => $this->classSessionId,
            'student_name' => $this->studentName,
        ];
    }

    public static function for(User $student, ClassSession $session): self
    {
        return new self($session->id, $student->full_name);
    }
}
