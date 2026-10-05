<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\YearSection;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rules\Password;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identity')
                    ->columns(2)
                    ->schema([
                        TextInput::make('first_name')
                            ->required()
                            ->maxLength(50),
                        TextInput::make('last_name')
                            ->required()
                            ->maxLength(50),
                        TextInput::make('username')
                            ->required()
                            ->maxLength(50)
                            ->unique(ignoreRecord: true)
                            ->helperText('Used to sign in. Letters, numbers, dots, dashes and underscores.'),
                        TextInput::make('email')
                            ->label('Email address')
                            ->email()
                            ->required()
                            ->maxLength(100)
                            ->unique(ignoreRecord: true),
                        TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->minLength(8)
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->helperText('Leave blank on edit to keep the current password.')
                            ->rule(Password::default()),
                        Select::make('status')
                            ->options(UserStatus::class)
                            ->default(UserStatus::Active)
                            ->required(),
                    ]),

                // Role, quota and the student fields all live in one Section
                // because they are interdependent. Splitting the student fields
                // into their own Section left `Get` resolving against that
                // Section's own container, whose state path is null — so
                // `$get('role')` always returned null and the fields were
                // permanently hidden.
                Section::make('Role and enrolment')
                    ->columns(2)
                    ->schema([
                        Select::make('role')
                            ->options(UserRole::class)
                            ->default(UserRole::Student)
                            ->required()
                            ->live(),
                        TextInput::make('storage_quota_mb')
                            ->label('Storage quota (MB)')
                            ->numeric()
                            ->minValue(0)
                            ->default(config('lab.storage.student_quota_mb'))
                            ->required()
                            ->helperText('Actual usage is calculated from the file drive and cannot be set here.'),
                        TextInput::make('student_id')
                            ->label('Student ID')
                            ->maxLength(20)
                            ->unique(ignoreRecord: true)
                            // A student identifier is meaningless on a staff account.
                            // Written as "not staff" rather than "is student"
                            // because a freshly rendered create page has no role
                            // state yet, and an empty state must show the fields
                            // rather than hide them.
                            ->visible(fn (Get $get): bool => ! in_array(
                                $get->enum('role', UserRole::class),
                                [UserRole::Teacher, UserRole::Admin],
                                true,
                            )),
                        Select::make('year_section_id')
                            ->label('Year and section')
                            // Options come from the model because `label` is an
                            // accessor, so `->relationship('yearSection', 'label')`
                            // would try to order by a non-existent SQL column.
                            ->options(fn (): Collection => YearSection::options())
                            ->searchable()
                            ->preload()
                            ->placeholder('Unassigned')
                            ->visible(fn (Get $get): bool => ! in_array(
                                $get->enum('role', UserRole::class),
                                [UserRole::Teacher, UserRole::Admin],
                                true,
                            )),
                    ]),
            ]);
    }
}
