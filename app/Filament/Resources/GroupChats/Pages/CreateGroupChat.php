<?php

namespace App\Filament\Resources\GroupChats\Pages;

use App\Enums\GroupRole;
use App\Filament\Resources\GroupChats\GroupChatResource;
use App\Filament\Resources\Pages\CreateRecord;

class CreateGroupChat extends CreateRecord
{
    protected static string $resource = GroupChatResource::class;

    /**
     * The creator joins as a group administrator, not a plain member.
     *
     * Otherwise they would not be able to moderate their own group — and
     * `GroupChatPolicy::leave` refuses to let a creator walk away from a group
     * they own, so a creator who was only a member would be stuck in it.
     */
    protected function afterCreate(): void
    {
        $this->getRecord()->join(auth()->user(), GroupRole::Admin);

        parent::afterCreate();
    }
}
