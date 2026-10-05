<?php

namespace App\Filament\Resources\Pcs\Pages;

use App\Filament\Resources\Pcs\PcResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPcs extends ListRecords
{
    protected static string $resource = PcResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
