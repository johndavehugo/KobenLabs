<?php

namespace App\Filament\Resources\StoredFiles\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class StoredFileInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('File')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('name')
                            ->label('Name')
                            ->weight('bold'),
                        TextEntry::make('user.full_name')
                            ->label('Owner'),
                        TextEntry::make('is_folder')
                            ->label('Type')
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Folder' : 'File'),
                        TextEntry::make('human_size')
                            ->label('Size')
                            ->placeholder('—'),
                        TextEntry::make('mime_type')
                            ->label('MIME type')
                            ->placeholder('—'),
                        TextEntry::make('created_at')
                            ->label('Added')
                            ->dateTime(),
                        TextEntry::make('path')
                            ->label('Storage path')
                            ->placeholder('Folders have no path of their own')
                            ->columnSpanFull(),
                    ]),

                Section::make('Owner storage')
                    ->description('Counted against this user\'s quota, as message and group chat attachments are too.')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('user.human_storage_used')
                            ->label('Used'),
                        TextEntry::make('user.human_storage_quota')
                            ->label('Quota'),
                        TextEntry::make('user.is_full_quota')
                            ->label('At quota')
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No'),
                    ]),
            ]);
    }
}
