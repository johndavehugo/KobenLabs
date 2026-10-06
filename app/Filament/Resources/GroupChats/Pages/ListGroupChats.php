<?php

namespace App\Filament\Resources\GroupChats\Pages;

use App\Filament\Resources\GroupChats\GroupChatResource;
use App\Filament\Resources\Pages\ListRecords;
use Filament\Actions\CreateAction;

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
