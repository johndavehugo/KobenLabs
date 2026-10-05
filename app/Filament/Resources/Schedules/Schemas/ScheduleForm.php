<?php

namespace App\Filament\Resources\Schedules\Schemas;

use App\Enums\ScheduleStatus;
use App\Models\User;
use App\Models\YearSection;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;

class ScheduleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Booking')
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')
                            ->label('Subject')
                            ->required()
                            ->maxLength(200),

                        Select::make('teacher_id')
                            ->label('Teacher')
                            ->options(fn (): Collection => User::staffOptions())
                            ->searchable()
                            ->required(),

                        Select::make('year_section_id')
                            ->label('Year and section')
                            // `label` on YearSection is an accessor, so
                            // ->relationship('yearSection', 'label') would try
                            // to order by a column that does not exist.
                            ->options(fn (): Collection => YearSection::options())
                            ->searchable()
                            ->preload()
                            ->placeholder('Whole year'),

                        TextInput::make('purpose')
                            ->maxLength(200)
                            ->placeholder('Lab session'),

                        Textarea::make('description')
                            ->columnSpanFull()
                            ->autosize(),
                    ]),

                Section::make('Time')
                    ->columns(2)
                    ->schema([
                        DateTimePicker::make('starts_at')
                            ->label('Starts')
                            ->seconds(false)
                            ->required(),

                        DateTimePicker::make('ends_at')
                            ->label('Ends')
                            ->seconds(false)
                            ->required()
                            ->helperText('Between '
                                .config('lab.schedules.min_duration_minutes')
                                .' and '
                                .config('lab.schedules.max_duration_minutes')
                                .' minutes.'),
                        // The overlap and duration rules live in
                        // ScheduleObserver rather than in this form, so they
                        // also apply to console and API writes. The observer
                        // raises against `starts_at` and `ends_at`, which
                        // Filament maps back to those two fields.

                        Select::make('status')
                            ->options(ScheduleStatus::class)
                            ->default(ScheduleStatus::Scheduled)
                            ->required()
                            ->helperText('Cancelled bookings release their slot and stop appearing as conflicts.'),
                    ]),
            ]);
    }
}
