<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\YearSections\Pages\CreateYearSection;
use App\Filament\Resources\YearSections\Pages\EditYearSection;
use App\Filament\Resources\YearSections\Pages\ListYearSections;
use App\Filament\Resources\YearSections\Pages\ViewYearSection;
use App\Filament\Resources\YearSections\RelationManagers\StudentsRelationManager;
use App\Models\User;
use App\Models\YearSection;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| Filament resources
|--------------------------------------------------------------------------
|
| Verifies that both resources render under the admin panel and that the
| policies from PolicyMatrixTest actually gate them. A resource that registers
| its routes is not the same as a resource that works.
|
*/

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->teacher = User::factory()->teacher()->create();
    $this->student = User::factory()->student()->create();

    actingAs($this->admin);
    Filament::setCurrentPanel('admin');
});

/*
|--------------------------------------------------------------------------
| User resource
|--------------------------------------------------------------------------
*/

it('loads the user list for staff', function () {
    livewire(ListUsers::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$this->admin, $this->teacher, $this->student]);
});

it('searches users by name', function () {
    livewire(ListUsers::class)
        ->assertCanSeeTableRecords([$this->admin, $this->teacher, $this->student])
        ->searchTable($this->student->first_name)
        ->assertCanSeeTableRecords([$this->student])
        ->assertCanNotSeeTableRecords([$this->teacher]);
});

it('filters users by role', function () {
    livewire(ListUsers::class)
        ->assertCanSeeTableRecords([$this->admin, $this->teacher, $this->student])
        ->filterTable('role', UserRole::Student->value)
        ->assertCanSeeTableRecords([$this->student])
        ->assertCanNotSeeTableRecords([$this->teacher, $this->admin]);
});

it('denies the user list to a student', function () {
    actingAs($this->student);

    livewire(ListUsers::class)->assertForbidden();
});

it('creates a user', function () {
    livewire(CreateUser::class)
        ->assertOk()
        ->fillForm([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'username' => 'ada',
            'email' => 'ada@kobenlabs.test',
            'password' => 'correct-horse-battery',
            'role' => UserRole::Teacher->value,
            'status' => UserStatus::Active->value,
            'storage_quota_mb' => 2000,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('users', [
        'username' => 'ada',
        'email' => 'ada@kobenlabs.test',
        'role' => 'teacher',
    ]);
});

it('shows student fields for a student role', function () {
    // No fillForm needed: student is the default role, and the visibility rule
    // is written as "not staff" so an empty state shows the fields.
    livewire(CreateUser::class)
        ->assertOk()
        ->assertFormFieldVisible('student_id')
        ->assertFormFieldVisible('year_section_id');
});

it('hides student fields for a staff role', function () {
    // Asserted on a fresh component: re-filling an already-hidden field inside
    // one component instance does not re-evaluate its visibility.
    livewire(CreateUser::class)
        ->assertOk()
        ->fillForm(['role' => UserRole::Admin->value])
        ->assertFormFieldHidden('student_id')
        ->assertFormFieldHidden('year_section_id');
});

it('keeps the existing password when the field is left blank on edit', function () {
    $original = $this->student->password;

    livewire(EditUser::class, ['record' => $this->student->getKey()])
        ->assertOk()
        ->fillForm(['first_name' => 'Renamed'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->student->refresh()->first_name)->toBe('Renamed')
        ->and($this->student->password)->toBe($original);
});

it('views a user record', function () {
    livewire(ViewUser::class, ['record' => $this->student->getKey()])
        ->assertOk()
        ->assertSee($this->student->email);
});

/*
|--------------------------------------------------------------------------
| Year section resource
|--------------------------------------------------------------------------
*/

it('loads the year and section list for staff', function () {
    $section = YearSection::factory()->create();

    livewire(ListYearSections::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$section]);
});

it('lets any signed-in user read the catalogue but only staff create entries', function () {
    actingAs($this->student);

    livewire(ListYearSections::class)
        ->assertOk()
        ->assertActionHidden('create');
});

it('creates a year and section', function () {
    livewire(CreateYearSection::class)
        ->assertOk()
        ->fillForm([
            'year' => 'BSIT 3',
            'section' => 'C',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('year_sections', [
        'year' => 'BSIT 3',
        'section' => 'C',
    ]);
});

it('adds a section to an existing year from the row action', function () {
    $year = YearSection::factory()->yearOnly()->create(['year' => 'BSIT 4']);

    livewire(ListYearSections::class)
        ->callTableAction('addSection', $year, ['section' => 'A'])
        ->assertHasNoActionErrors();

    $this->assertDatabaseHas('year_sections', [
        'year' => 'BSIT 4',
        'section' => 'A',
    ]);
});

it('lists the students enrolled in a section', function () {
    $section = YearSection::factory()->create();

    livewire(EditYearSection::class, ['record' => $section->getKey()])
        ->assertOk()
        ->assertSeeLivewire(StudentsRelationManager::class);

    livewire(StudentsRelationManager::class, [
        'ownerRecord' => $section,
        'pageClass' => EditYearSection::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords($section->students);
});

it('creates a student inside a section', function () {
    $section = YearSection::factory()->create();

    livewire(StudentsRelationManager::class, [
        'ownerRecord' => $section,
        'pageClass' => EditYearSection::class,
    ])
        ->callAction(
            TestAction::make(CreateAction::class)->table(),
            [
                'first_name' => 'Grace',
                'last_name' => 'Hopper',
                'username' => 'grace',
                'email' => 'grace@kobenlabs.test',
                'student_id' => 'STU2024009',
                'status' => UserStatus::Active->value,
                'password' => 'correct-horse-battery',
            ],
        )
        ->assertHasNoActionErrors();

    $grace = User::query()->where('username', 'grace')->firstOrFail();

    expect($grace->role)->toBe(UserRole::Student)
        ->and($grace->year_section_id)->toBe($section->id);
});

it('views a year and section record', function () {
    $section = YearSection::factory()->create(['year' => 'BSIT 2', 'section' => 'B']);

    livewire(ViewYearSection::class, ['record' => $section->getKey()])
        ->assertOk()
        ->assertSee('BSIT 2');
});
