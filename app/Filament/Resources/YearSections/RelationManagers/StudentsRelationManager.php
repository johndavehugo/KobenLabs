<?php

namespace App\Filament\Resources\YearSections\RelationManagers;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Password;

/**
 * The students enrolled in one year and section.
 *
 * Replaces the legacy Students tab, which drove every create, edit and delete
 * through `users-api.php` and `year-sections-api.php` — two scripts with,
 * respectively, no role check and no authentication at all.
 *
 * The form here is deliberately narrower than UserForm: role is always student
 * and the section comes from the relation, so neither is offered.
 */
class StudentsRelationManager extends RelationManager
{
    protected static string $relationship = 'students';

    protected static ?string $title = 'Students';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('full_name')
            ->defaultSort('last_name')
            ->columns([
                TextColumn::make('full_name')
                    ->label('Name')
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(['first_name', 'last_name'])
                    ->weight('bold'),
                TextColumn::make('student_id')
                    ->label('Student ID')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('username')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('last_login_at')
                    ->label('Last seen')
                    ->dateTime()
                    ->placeholder('Never')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Add student')
                    // Role is implied by the relationship, not chosen.
                    ->mutateDataUsing(fn (array $data): array => [
                        ...$data,
                        'role' => UserRole::Student,
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('first_name')
                    ->required()
                    ->maxLength(50),
                TextInput::make('last_name')
                    ->required()
                    ->maxLength(50),
                TextInput::make('username')
                    ->required()
                    ->maxLength(50)
                    ->unique(ignoreRecord: true),
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required()
                    ->maxLength(100)
                    ->unique(ignoreRecord: true),
                TextInput::make('student_id')
                    ->label('Student ID')
                    ->maxLength(20)
                    ->unique(ignoreRecord: true),
                Select::make('status')
                    ->options(UserStatus::class)
                    ->default(UserStatus::Active)
                    ->required(),
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->minLength(8)
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->helperText('Leave blank on edit to keep the current password.')
                    ->rule(Password::default())
                    ->columnSpanFull(),
            ]);
    }
}
