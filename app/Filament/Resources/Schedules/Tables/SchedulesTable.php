<?php

namespace App\Filament\Resources\Schedules\Tables;

use App\Enums\ScheduleStatus;
use App\Models\Schedule;
use App\Models\User;
use App\Models\YearSection;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class SchedulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('starts_at')
            ->columns([
                TextColumn::make('title')
                    ->label('Subject')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('day_of_week')
                    ->label('Day')
                    ->sortable(['starts_at'])
                    ->description(fn (Schedule $record): string => sprintf(
                        '%s – %s',
                        $record->start_time,
                        $record->end_time,
                    )),

                TextColumn::make('starts_at')
                    ->label('Starts')
                    ->dateTime('j M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('duration_minutes')
                    ->label('Length')
                    ->formatStateUsing(fn (int $state): string => $state.' min')
                    ->description(fn (Schedule $record): ?string => $record->is_valid_duration
                        ? null
                        : 'Outside the permitted window')
                    ->sortable(['starts_at']),

                TextColumn::make('teacher.full_name')
                    ->label('Teacher')
                    ->placeholder('Unassigned')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('yearSection.label')
                    ->label('Year and section')
                    ->placeholder('Whole year')
                    ->toggleable(),

                TextColumn::make('status')
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(ScheduleStatus::class),
                SelectFilter::make('teacher_id')
                    ->label('Teacher')
                    ->options(fn (): Collection => User::staffOptions())
                    ->searchable(),
                SelectFilter::make('year_section_id')
                    ->label('Year and section')
                    ->options(fn (): Collection => YearSection::options()),
            ])
            ->recordActions([
                Action::make('cancel')
                    ->label('Cancel booking')
                    ->icon('heroicon-m-x-circle')
                    ->color('danger')
                    ->visible(fn (Schedule $record): bool => $record->status !== ScheduleStatus::Cancelled)
                    // A teacher may only cancel their own booking; a lab
                    // administrator may cancel anyone's.
                    ->authorize(fn (Schedule $record): bool => auth()->user()?->can('cancel', $record) ?? false)
                    ->requiresConfirmation()
                    ->action(fn (Schedule $record) => $record->forceFill([
                        'status' => ScheduleStatus::Cancelled,
                    ])->save()),

                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
