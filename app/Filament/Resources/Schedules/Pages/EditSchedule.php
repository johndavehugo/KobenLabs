<?php

namespace App\Filament\Resources\Schedules\Pages;

use App\Filament\Resources\Concerns\GuardsScheduleBooking;
use App\Filament\Resources\Pages\EditRecord;
use App\Filament\Resources\Schedules\ScheduleResource;
use Filament\Actions\DeleteAction;

class EditSchedule extends EditRecord
{
    use GuardsScheduleBooking;

    protected static string $resource = ScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * Exclude the record being edited from the clash check, otherwise saving an
     * unchanged booking would conflict with itself.
     */
    protected function beforeSave(): void
    {
        $this->guardScheduleBooking($this->getRecord()?->getKey());
    }
}
