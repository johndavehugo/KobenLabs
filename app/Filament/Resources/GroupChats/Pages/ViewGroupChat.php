<?php

namespace App\Filament\Resources\GroupChats\Pages;

use App\Filament\Resources\GroupChats\GroupChatResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

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
