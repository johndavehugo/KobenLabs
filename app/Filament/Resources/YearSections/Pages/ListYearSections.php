<?php

namespace App\Filament\Resources\YearSections\Pages;

use App\Filament\Resources\YearSections\YearSectionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListYearSections extends ListRecords
{
    protected static string $resource = YearSectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
