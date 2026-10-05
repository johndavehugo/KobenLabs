<?php

namespace App\Filament\Resources\Concerns;

use App\Rules\ScheduleBookingRules;

/**
 * Surfaces ScheduleObserver's ValidationException as inline form errors.
 *
 * Without this the observer still prevents the bad write, but the user sees an
 * unhandled 422 instead of a message under the offending field. The rules
 * themselves are not duplicated — this calls the same class the observer uses.
 */
trait GuardsScheduleBooking
{
    protected function guardScheduleBooking(?int $ignoreScheduleId = null): void
    {
        $errors = app(ScheduleBookingRules::class)->errors(
            $this->form->getState(),
            $ignoreScheduleId,
        );

        if ($errors === []) {
            return;
        }

        $this->addFormErrors($errors);
    }

    /**
     * Filament 5 removed `Schema::addErrors()`, so errors are written straight to
     * Livewire's bag under the schema's state path — which is what Filament
     * renders form errors from.
     *
     * @param  array<string, list<string>>  $errors
     */
    private function addFormErrors(array $errors): void
    {
        foreach ($errors as $field => $messages) {
            foreach ($messages as $message) {
                $this->addError('data.'.$field, $message);
            }
        }

        $this->halt();
    }
}
