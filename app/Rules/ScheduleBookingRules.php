<?php

namespace App\Rules;

use App\Enums\ScheduleStatus;
use App\Models\Schedule;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * The booking rules, in one place, callable from two layers.
 *
 * Both a Filament form and ScheduleObserver need to answer "is this booking
 * valid?", and they need to answer it identically — the legacy app checked only
 * in the controller, so a console write or a second route bypassed both rules
 * entirely. The observer enforces the invariant for every write path; the pages
 * call the same class so the failure appears as an inline form error rather than
 * an unhandled 422.
 */
class ScheduleBookingRules
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, list<string>> Field name to messages. Empty when valid.
     */
    public function errors(array $data, ?int $ignoreScheduleId = null): array
    {
        $errors = [];

        $this->checkDuration($data, $errors);
        $this->checkConflicts($data, $ignoreScheduleId, $errors);

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, list<string>>  $errors
     */
    private function checkDuration(array $data, array &$errors): void
    {
        $startsAt = $this->asDate($data['starts_at'] ?? null);
        $endsAt = $this->asDate($data['ends_at'] ?? null);

        if ($startsAt === null || $endsAt === null) {
            return;
        }

        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            $errors['ends_at'][] = 'The end time must be after the start time.';

            return;
        }

        $minutes = (int) $startsAt->diffInMinutes($endsAt);
        $min = (int) config('lab.schedules.min_duration_minutes');
        $max = (int) config('lab.schedules.max_duration_minutes');

        // The legacy app stated these bounds three different ways: 30/240 in
        // config.php, 30/480 in the controller, and 30/240 in system_config. One
        // value now lives in config/lab.php.
        if ($minutes < $min || $minutes > $max) {
            $errors['ends_at'][] = "A session must run between {$min} and {$max} minutes. This one is {$minutes}.";
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, list<string>>  $errors
     */
    private function checkConflicts(array $data, ?int $ignoreScheduleId, array &$errors): void
    {
        $startsAt = $this->asDate($data['starts_at'] ?? null);
        $endsAt = $this->asDate($data['ends_at'] ?? null);

        if ($startsAt === null || $endsAt === null) {
            return;
        }

        // A cancelled booking holds no claim on the room. Filament casts the submitted
        // value to the enum before this runs; a direct model write passes a
        // string, so both shapes are accepted.
        $status = $data['status'] ?? null;

        if ($status !== null && ! $this->asScheduleStatus($status)?->blocksConflicts()) {
            return;
        }

        $candidate = new Schedule([
            'teacher_id' => $data['teacher_id'] ?? null,
            'year_section_id' => $data['year_section_id'] ?? null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);

        $conflicts = $candidate->conflictsWith($ignoreScheduleId);

        if ($conflicts->isEmpty()) {
            return;
        }

        $errors['starts_at'][] = 'This clashes with an existing booking: '.$conflicts
            ->map(fn (Schedule $conflict) => sprintf(
                '%s (%s–%s)',
                $conflict->title,
                $conflict->starts_at->format('D j M H:i'),
                $conflict->ends_at->format('H:i'),
            ))
            ->implode('; ');
    }

    private function asScheduleStatus(mixed $value): ?ScheduleStatus
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof ScheduleStatus
            ? $value
            : ScheduleStatus::tryFrom((string) $value);
    }

    private function asDate(mixed $value): ?CarbonInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof CarbonInterface
            ? $value
            : Carbon::parse($value);
    }
}
