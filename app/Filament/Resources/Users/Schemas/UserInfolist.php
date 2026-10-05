<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identity')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('full_name')
                            ->label('Name'),
                        TextEntry::make('username'),
                        TextEntry::make('email')
                            ->label('Email address'),
                        TextEntry::make('status')
                            ->badge(),
                        TextEntry::make('last_login_at')
                            ->label('Last seen')
                            ->dateTime()
                            ->placeholder('Never'),
                    ]),

                Section::make('Role')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('role')
                            ->badge(),
                        TextEntry::make('student_id')
                            ->label('Student ID')
                            ->placeholder('-')
                            ->hidden(fn ($record): bool => ! $record->isStudent()),
                        TextEntry::make('yearSection.label')
                            ->label('Year and section')
                            ->placeholder('Unassigned')
                            ->hidden(fn ($record): bool => ! $record->isStudent()),
                    ]),

                Section::make('Storage')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('storage_quota_mb')
                            ->label('Quota (MB)')
                            ->numeric(),
                        TextEntry::make('storage_used_mb')
                            ->label('Used (MB)')
                            ->numeric()
                            ->helperText(fn ($record): ?string => $record->storageQuotaBytes() > 0
                                ? round($record->storageUsedBytes() / 1024 / 1024, 2).' MB of '
                                    .round($record->storageQuotaBytes() / 1024 / 1024).' MB'
                                : null),
                    ]),

                Section::make('Audit')
                    ->columns(2)
                    ->collapsed()
                    ->schema([
                        TextEntry::make('email_verified_at')
                            ->label('Email verified')
                            ->dateTime()
                            ->placeholder('Not verified'),
                        TextEntry::make('created_at')
                            ->label('Created')
                            ->dateTime(),
                        TextEntry::make('updated_at')
                            ->label('Updated')
                            ->dateTime(),
                    ]),
            ]);
    }
}
