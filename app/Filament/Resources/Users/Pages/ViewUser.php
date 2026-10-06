<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Pages\ViewRecord;
use App\Filament\Resources\Users\UserResource;
use Filament\Actions\EditAction;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
