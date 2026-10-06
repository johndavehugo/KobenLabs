<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A student's own attendance record.
 *
 * Legacy had a complete student attendance summary view that nothing ever linked
 * to — there was no route to it and no nav item. The equivalent surface is built
 * here rather than ported, because it is genuinely useful to a student and there
 * is nothing to copy.
 *
 * The awkward part of the model is that `attendances` records only *presence*. There
 * is no row for a student who did not join, so "how often was I absent" can only be
 * derived by comparing attendance rows against the sessions held for that student's
 * year and section. That comparison is what this service exists to do, in one place,
 * so the rate shown to a student and the rate shown to staff cannot disagree.
 */
class AttendanceSummaryService
{
    /**
     * Completed sessions held for the student's year and section.
     *
     * A student with no year section has no denominator, so the summary reports
     * zero rather than inventing one — an unassigned student attending every class
     * would otherwise read as 100%.
     */
    public function heldCount(User $student): int
    {
        $yearSectionId = $student->year_section_id;

        if ($yearSectionId === null) {
            return 0;
        }

        return ClassSession::query()
            ->completed()
            ->whereHas('schedule', fn (Builder $schedule) => $schedule
                ->where('year_section_id', $yearSectionId))
            ->count();
    }

    public function attendedCount(User $student): int
    {
        return $student->attendance()->count();
    }

    /**
     * Attended as a percentage of sessions held, or null when there is no
     * denominator.
     */
    public function rate(User $student): ?float
    {
        $held = $this->heldCount($student);

        if ($held === 0) {
            return null;
        }

        return round(($this->attendedCount($student) / $held) * 100, 1);
    }

    /**
     * Per-subject breakdown, so a student can see which class they are missing
     * rather than just a single number.
     *
     * @return Collection<int, object>
     */
    public function bySubject(User $student): Collection
    {
        $yearSectionId = $student->year_section_id;

        if ($yearSectionId === null) {
            return collect();
        }

        $subjects = Schedule::query()
            ->where('year_section_id', $yearSectionId)
            ->pluck('title')
            ->unique();

        return $subjects
            ->map(fn (string $subject): object => (object) [
                'subject' => $subject,
                'held' => $this->heldForSubject($yearSectionId, $subject),
                'attended' => $this->attendedForSubject($student, $subject),
            ])
            ->map(function (object $row): object {
                $row->rate = $row->held === 0
                    ? null
                    : round(($row->attended / $row->held) * 100, 1);

                return $row;
            })
            ->sortByDesc(fn (object $row): int => $row->held)
            ->values();
    }

    private function heldForSubject(int $yearSectionId, string $subject): int
    {
        return ClassSession::query()
            ->completed()
            ->whereHas('schedule', fn (Builder $schedule) => $schedule
                ->where('year_section_id', $yearSectionId)
                ->where('title', $subject))
            ->count();
    }

    private function attendedForSubject(User $student, string $subject): int
    {
        return $student->attendance()
            ->whereHas('classSession.schedule', fn (Builder $schedule) => $schedule
                ->where('title', $subject))
            ->count();
    }

    /**
     * Sessions the student attended, newest first, with enough context to be
     * useful on its own.
     *
     * @return Collection<int, ClassSession>
     */
    public function recentSessions(User $student, int $limit = 25): Collection
    {
        return ClassSession::query()
            ->with(['schedule.teacher', 'attendance'])
            ->whereHas('attendance', fn (Builder $attendance) => $attendance
                ->where('user_id', $student->id))
            ->latest('started_at')
            ->limit($limit)
            ->get();
    }
}
