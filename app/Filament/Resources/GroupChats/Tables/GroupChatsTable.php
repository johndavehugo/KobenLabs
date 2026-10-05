<?php

namespace App\Filament\Resources\GroupChats\Tables;

use App\Models\GroupChat;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class GroupChatsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),

                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->description(fn (GroupChat $record): ?string => $record->description)
                    ->wrap(),

                TextColumn::make('join_code')
                    ->label('Join code')
                    // The credential itself. Staff need it to share the group, and
                    // staff are already trusted with every transcript.
                    ->badge()
                    ->copyable(),

                TextColumn::make('members_count')
                    ->label('Members')
                    ->counts('members'),

                TextColumn::make('chat_messages_count')
                    ->label('Messages')
                    ->counts('chatMessages'),

                TextColumn::make('creator.full_name')
                    ->label('Created by')
                    ->placeholder('A deleted account'),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Active only')
                    ->trueLabel('Active')
                    ->falseLabel('Inactive'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete'),
                ]),
            ])
            ->defaultSort('name');
    }
}
