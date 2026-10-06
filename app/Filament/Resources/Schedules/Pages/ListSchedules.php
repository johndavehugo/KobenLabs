<?php

namespace App\Filament\Resources\Schedules\Pages;

use App\Filament\Resources\Pages\ListRecords;
use App\Filament\Resources\Schedules\ScheduleResource;
use Filament\Actions\CreateAction;

class ListSchedules extends ListRecords
{
    protected static string $resource = ScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
