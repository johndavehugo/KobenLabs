<?php

namespace App\Filament\Resources\Pcs\Pages;

use App\Filament\Resources\Pcs\PcResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPc extends EditRecord
{
    protected static string $resource = PcResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
