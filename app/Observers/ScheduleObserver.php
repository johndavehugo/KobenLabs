<?php

namespace App\Observers;

use App\Models\Schedule;
use App\Rules\ScheduleBookingRules;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Enforces the schedule invariants on every write path.
 *
 * The legacy app checked both rules inside `ScheduleController`, so they only
 * applied to that one HTTP entry point — anything writing a schedule through
 * another route, a console command or tinker bypassed them entirely. An observer
 * makes the rules a property of the model rather than of a controller.
 *
 * The predicate lives in ScheduleBookingRules, which the Filament pages also call
 * so the same failure surfaces as an inline form error. Both raise a
 * `ValidationException` naming a real field.
 */
class ScheduleObserver
{
    public function __construct(private readonly ScheduleBookingRules $rules) {}

    public function saving(Schedule $schedule): void
    {
        $errors = $this->rules->errors(
            [
                'teacher_id' => $schedule->teacher_id,
                'year_section_id' => $schedule->year_section_id,
                'starts_at' => $schedule->starts_at,
                'ends_at' => $schedule->ends_at,
                'status' => $schedule->status?->value,
            ],
            $schedule->getKey(),
        );

        if ($errors === []) {
            return;
        }

        if (isset($errors['starts_at'])) {
            Log::info('Schedule conflict rejected', [
                'schedule_id' => $schedule->getKey(),
                'message' => $errors['starts_at'][0],
            ]);
        }

        throw ValidationException::withMessages($errors);
    }
}
