<?php

namespace App\Services;

use App\Enums\ClassSessionStatus;
use App\Events\ClassSessionEnded;
use App\Events\ClassSessionStarted;
use App\Events\StudentJoinedClass;
use App\Models\ClassSession;
use App\Models\Schedule;
use App\Models\User;

/**
 * The academic loop: start a class from a schedule, take attendance, end it.
 *
 * The legacy app spread this across `ClassSessionController` and
 * `Attendance::recordAttendance()` with no shared entry point, which is why the
 * `student_joined` broadcast had to be fired manually from one place and the
 * idempotency of joining was implemented differently in each.
 */
class ClassSessionService
{
    /**
     * Start a class. Refuses while another is running — enforced by
     * ClassSessionObserver, which holds a cache lock across the check and the
     * insert so two concurrent requests cannot both pass.
     */
    public function start(Schedule $schedule, User $teacher): ClassSession
    {
        $session = ClassSession::query()->create([
            'schedule_id' => $schedule->id,
            'started_by_id' => $teacher->id,
            'started_at' => now(),
            'status' => ClassSessionStatus::Active,
        ]);

        // `event()` rather than a bare constructor: the named constructors on
        // the events build a payload, they do not dispatch.
        event(ClassSessionStarted::forSession($session->load(['schedule', 'startedBy'])));

        return $session;
    }

    /**
     * End a running class. Idempotent.
     */
    public function end(ClassSession $session): ClassSession
    {
        if ($session->isActive()) {
            $session->end();

            ClassSessionEnded::dispatch($session->id);
        }

        return $session;
    }

    /**
     * Record a student's attendance.
     *
     * @return bool True when a new record was written, false when they were
     *              already on the roster.
     */
    public function join(ClassSession $session, User $student): bool
    {
        if (! $session->isActive()) {
            return false;
        }

        if (! $session->recordAttendance($student)) {
            return false;
        }

        event(StudentJoinedClass::for($student, $session));

        return true;
    }

    public function active(): ?ClassSession
    {
        return ClassSession::query()
            ->active()
            ->with(['schedule', 'startedBy'])
            ->latest('started_at')
            ->first();
    }
}
