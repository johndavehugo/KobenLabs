<?php

namespace App\Filament\Resources\YearSections\Pages;

use App\Filament\Resources\YearSections\YearSectionResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

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
