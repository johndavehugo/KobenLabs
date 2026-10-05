<?php

namespace App\Filament\Resources\YearSections\Tables;

use App\Models\YearSection;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class YearSectionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('year')
            ->columns([
                TextColumn::make('label')
                    ->label('Year and section')
                    ->searchable(['year', 'section'])
                    ->sortable(['year', 'section'])
                    ->description(fn (YearSection $record): ?string => $record->section === null
                        ? 'Year only — no section assigned yet'
                        : null)
                    ->weight('bold'),

                TextColumn::make('students_count')
                    ->label('Students')
                    ->counts('students')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('is_active')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Status')
                    ->default(true),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('addSection')
                    ->label('Add section')
                    ->icon('heroicon-m-plus')
                    // Adding a section to an existing year, rather than
                    // creating a duplicate year, was an explicit legacy rule:
                    // posting a duplicate year returned 409 with "Year already
                    // exists. Please add a section to this year instead."
                    ->form([
                        TextInput::make('section')
                            ->label('Section')
                            ->required()
                            ->maxLength(50),
                    ])
                    ->action(function (array $data, YearSection $record): void {
                        YearSection::query()->firstOrCreate(
                            ['year' => $record->year, 'section' => $data['section']],
                            ['is_active' => true],
                        );
                    })
                    ->successNotificationTitle('Section added'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
