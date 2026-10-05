<?php

namespace App\Filament\Resources\ClassSessions\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ClassSessionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Session')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('schedule.title')
                            ->label('Subject')
                            ->weight('bold'),
                        TextEntry::make('startedBy.full_name')
                            ->label('Started by'),
                        TextEntry::make('started_at')
                            ->label('Started')
                            ->dateTime(),
                        TextEntry::make('ended_at')
                            ->label('Ended')
                            ->dateTime()
                            ->placeholder('Still running'),
                        TextEntry::make('status')
                            ->badge(),
                        TextEntry::make('attendance_count')
                            ->label('Attendees')
                            ->counts('attendance'),
                    ]),

                Section::make('Booking')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('schedule.yearSection.label')
                            ->label('Year and section')
                            ->placeholder('Whole year'),
                        TextEntry::make('schedule.teacher.full_name')
                            ->label('Teacher')
                            ->placeholder('Unassigned'),
                        TextEntry::make('schedule.start_time')
                            ->label('Starts'),
                        TextEntry::make('schedule.end_time')
                            ->label('Ends'),
                    ]),
            ]);
    }
}
