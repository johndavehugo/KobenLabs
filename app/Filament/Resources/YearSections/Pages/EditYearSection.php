<?php

namespace App\Filament\Resources\YearSections\Pages;

use App\Filament\Resources\Pages\EditRecord;
use App\Filament\Resources\YearSections\YearSectionResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;

class EditYearSection extends EditRecord
{
    protected static string $resource = YearSectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
