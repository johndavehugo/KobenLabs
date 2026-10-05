<?php

namespace App\Filament\Resources\StoredFiles\Tables;

use App\Models\StoredFile;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class StoredFilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                IconColumn::make('is_folder')
                    ->label('')
                    ->icon(fn (StoredFile $record) => $record->is_folder
                        ? Heroicon::Folder
                        : Heroicon::Document)
                    ->color(fn (StoredFile $record) => $record->is_folder ? 'warning' : 'gray'),

                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->description(fn (StoredFile $record): ?string => $record->is_folder
                        ? 'Folder'
                        : $record->path)
                    ->wrap(),

                TextColumn::make('user.full_name')
                    ->label('Owner')
                    ->searchable(['first_name', 'last_name', 'username'])
                    ->sortable(),

                TextColumn::make('human_size')
                    ->label('Size')
                    ->alignEnd()
                    ->sortable(['size']),

                TextColumn::make('mime_type')
                    ->label('Type')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Added')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('user')
                    ->label('Owner')
                    ->relationship('user', 'username')
                    ->searchable()
                    ->preload(),

                TernaryFilter::make('is_folder')
                    ->label('Folders only')
                    ->trueLabel('Folders')
                    ->falseLabel('Files'),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
