<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\YearSection;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('last_name')
            ->columns([
                TextColumn::make('full_name')
                    ->label('Name')
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(['first_name', 'last_name']),
                TextColumn::make('username')
                    ->searchable(),
                TextColumn::make('student_id')
                    ->label('Student ID')
                    ->searchable()
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('yearSection.label')
                    ->label('Year and section')
                    ->searchable()
                    ->sortable()
                    ->placeholder('Unassigned')
                    ->toggleable(),
                TextColumn::make('role')
                    ->badge(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('storage')
                    ->label('Storage')
                    ->description(fn ($record): ?string => $record->storageQuotaBytes() > 0
                        ? round($record->storageUsedBytes() / 1024 / 1024, 1).' of '
                            .round($record->storageQuotaBytes() / 1024 / 1024).' MB'
                        : null)
                    ->state(fn ($record): string => $record->storage_used_mb.' MB')
                    ->numeric()
                    ->sortable(['storage_used_mb'])
                    ->toggleable(),
                TextColumn::make('last_login_at')
                    ->label('Last seen')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('Never')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->options(UserRole::class),
                SelectFilter::make('status')
                    ->options(UserStatus::class),
                SelectFilter::make('year_section_id')
                    ->label('Year and section')
                    ->options(fn (): Collection => YearSection::options()),
                SelectFilter::make('has_year_section')
                    ->label('Assigned to a section')
                    ->options([
                        'yes' => 'Assigned',
                        'no' => 'Unassigned',
                    ])
                    ->query(function ($query, array $data): void {
                        if (($data['value'] ?? null) === null) {
                            return;
                        }

                        $data['value'] === 'yes'
                            ? $query->whereNotNull('year_section_id')
                            : $query->whereNull('year_section_id');
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
