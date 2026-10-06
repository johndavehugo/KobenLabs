<?php

namespace App\Filament\Resources\Schedules\Pages;

use App\Filament\Resources\Concerns\GuardsScheduleBooking;
use App\Filament\Resources\Pages\CreateRecord;
use App\Filament\Resources\Schedules\ScheduleResource;

class CreateSchedule extends CreateRecord
{
    use GuardsScheduleBooking;

    protected static string $resource = ScheduleResource::class;

    /**
     * Run after field-level validation and before the model is written, so a
     * clash shows up against the start time rather than as a failed save.
     */
    protected function beforeCreate(): void
    {
        $this->guardScheduleBooking();
    }
}
