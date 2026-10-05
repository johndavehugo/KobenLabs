<?php

namespace App\Filament\Resources\GroupChats\Pages;

use App\Filament\Resources\GroupChats\GroupChatResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListGroupChats extends ListRecords
{
    protected static string $resource = GroupChatResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
