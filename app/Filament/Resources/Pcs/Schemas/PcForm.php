<?php

namespace App\Filament\Resources\Pcs\Schemas;

use App\Enums\PcStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PcForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(100)
                    ->helperText('How the machine is labelled in the room.'),

                TextInput::make('ip_address')
                    ->label('IP address')
                    ->required()
                    ->maxLength(45)
                    ->unique(ignoreRecord: true)
                    ->ip()
                    ->helperText('This is how a PC is identified. Sign-ins from this address bring the machine online.'),

                TextInput::make('mac_address')
                    ->label('MAC address')
                    ->maxLength(17)
                    ->placeholder('AA:BB:CC:DD:EE:FF')
                    ->helperText('Optional reference data. Not used for identification.'),

                Select::make('status')
                    ->options(PcStatus::class)
                    ->default(PcStatus::Offline)
                    ->required(),
            ]);
    }
}
