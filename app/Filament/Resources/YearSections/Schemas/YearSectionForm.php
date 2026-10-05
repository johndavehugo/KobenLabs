<?php

namespace App\Filament\Resources\YearSections\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class YearSectionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('year')
                    ->required()
                    ->maxLength(50)
                    ->helperText('For example: BSIT 1.'),

                // A section is nullable in the schema because an academic year
                // exists before its first section is added. That is also why the
                // unique constraint on (year, section) permits a single null.
                TextInput::make('section')
                    ->maxLength(50)
                    ->helperText('Leave blank for the year itself, then add sections separately.'),

                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true)
                    ->helperText('Inactive years and sections are hidden from selection but keep their history.'),
            ]);
    }
}
