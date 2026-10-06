<?php

namespace App\Filament\Resources\GroupChats\Pages;

use App\Filament\Resources\GroupChats\GroupChatResource;
use App\Filament\Resources\Pages\ViewRecord;
use Filament\Actions\EditAction;

class ViewGroupChat extends ViewRecord
{
    protected static string $resource = GroupChatResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
