<?php

namespace App\Filament\Resources\GroupChats\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class GroupChatInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Group')->columns(2)->schema([
                    TextEntry::make('name')->label('Name')->weight('bold'),
                    TextEntry::make('is_active')
                        ->label('Active')
                        ->badge()
                        ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive'),
                    TextEntry::make('join_code')->label('Join code')->badge()->copyable(),
                    TextEntry::make('creator.full_name')
                        ->label('Created by')
                        ->placeholder('A deleted account'),
                    TextEntry::make('created_at')->label('Created')->dateTime(),
                    TextEntry::make('chat_messages_count')
                        ->label('Messages')
                        ->counts('chatMessages'),
                    TextEntry::make('description')
                        ->label('Description')
                        ->placeholder('No description')
                        ->columnSpanFull(),
                ]),

                Section::make('Chat')
                    ->description('Open the transcript from Group Chat on this panel.')
                    ->schema([
                        TextEntry::make('members_count')
                            ->label('Members')
                            ->counts('members'),
                    ]),
            ]);
    }
}
