<?php

namespace App\Filament\Resources\YearSections\Pages;

use App\Filament\Resources\Pages\ViewRecord;
use App\Filament\Resources\YearSections\YearSectionResource;
use Filament\Actions\EditAction;

class ViewYearSection extends ViewRecord
{
    protected static string $resource = YearSectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
