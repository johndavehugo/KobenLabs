<?php

namespace App\Filament\Resources\YearSections\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class YearSectionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Year and section')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('year')
                            ->weight('bold'),
                        TextEntry::make('section')
                            ->placeholder('No section assigned yet'),
                        TextEntry::make('is_active')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive')
                            ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                        TextEntry::make('students_count')
                            ->label('Enrolled students')
                            ->counts('students')
                            ->numeric(),
                    ]),

                Section::make('Audit')
                    ->columns(2)
                    ->collapsed()
                    ->schema([
                        TextEntry::make('created_at')
                            ->label('Created')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('updated_at')
                            ->label('Updated')
                            ->dateTime()
                            ->placeholder('-'),
                    ]),
            ]);
    }
}
