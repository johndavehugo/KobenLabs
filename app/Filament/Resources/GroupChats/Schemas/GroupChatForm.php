<?php

namespace App\Filament\Resources\GroupChats\Schemas;

use App\Models\GroupChat;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class GroupChatForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Group')->columns(2)->schema([
                    TextInput::make('name')
                        ->label('Name')
                        ->required()
                        ->maxLength(255),

                    // The join code is the group's credential: GroupChatPolicy::join
                    // grants access to anyone holding an active one, so it has to
                    // be long and unpredictable.
                    TextInput::make('join_code')
                        ->label('Join code')
                        ->required()
                        ->alphaDash()
                        ->default(fn (): string => GroupChat::generateJoinCode())
                        // Regenerating changes who can find the group, so it is
                        // deliberate rather than incidental.
                        ->helperText('Share this with members. Changing it revokes the old code.'),

                    Toggle::make('is_active')
                        ->label('Active')
                        ->helperText('An inactive group cannot be joined, even with the right code.')
                        ->default(true),
                ]),

                Section::make('Details')->schema([
                    Textarea::make('description')
                        ->label('Description')
                        ->rows(3)
                        ->maxLength(1000),
                ]),
            ]);
    }
}
