<?php

namespace App\Filament\Resources\ClassSessions\Tables;

use App\Enums\ClassSessionStatus;
use App\Models\ClassSession;
use App\Services\ClassSessionService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ClassSessionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('schedule.title')
                    ->label('Subject')
                    ->searchable(['title'])
                    ->weight('bold')
                    ->description(fn (ClassSession $record): ?string => $record->isActive()
                        ? 'Running now'
                        : null),

                TextColumn::make('schedule.yearSection.label')
                    ->label('Year and section')
                    ->placeholder('Whole year')
                    ->toggleable(),

                TextColumn::make('startedBy.full_name')
                    ->label('Teacher')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('attendance_count')
                    ->label('Attendees')
                    ->counts('attendance')
                    ->numeric(),

                TextColumn::make('started_at')
                    ->label('Started')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('ended_at')
                    ->label('Ended')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('status')
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(ClassSessionStatus::class),
            ])
            ->recordActions([
                Action::make('end')
                    ->label('End class')
                    ->icon('heroicon-m-stop')
                    ->color('danger')
                    ->visible(fn (ClassSession $record): bool => $record->isActive())
                    ->authorize(fn (ClassSession $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->requiresConfirmation()
                    // Idempotent, and the broadcast only fires when a live
                    // session was actually closed.
                    ->action(fn (ClassSession $record) => app(ClassSessionService::class)->end($record)),

                ViewAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
