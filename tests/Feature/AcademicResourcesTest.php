<?php

use App\Enums\ClassSessionStatus;
use App\Enums\ScheduleStatus;
use App\Filament\Resources\ClassSessions\Pages\CreateClassSession;
use App\Filament\Resources\ClassSessions\Pages\ListClassSessions;
use App\Filament\Resources\ClassSessions\Pages\ViewClassSession;
use App\Filament\Resources\ClassSessions\RelationManagers\AttendancesRelationManager;
use App\Filament\Resources\Schedules\Pages\CreateSchedule;
use App\Filament\Resources\Schedules\Pages\EditSchedule;
use App\Filament\Resources\Schedules\Pages\ListSchedules;
use App\Models\ClassSession;
use App\Models\Schedule;
use App\Models\User;
use App\Models\YearSection;
use Filament\Facades\Filament;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->teacher = User::factory()->teacher()->create();
    $this->student = User::factory()->student()->create();

    actingAs($this->admin);
    Filament::setCurrentPanel('admin');
});

/*
|--------------------------------------------------------------------------
| Schedule resource
|--------------------------------------------------------------------------
*/

it('loads the timetable for everyone signed in', function () {
    $schedules = Schedule::factory()->count(3)->create();

    livewire(ListSchedules::class)
        ->assertOk()
        ->assertCanSeeTableRecords($schedules);
});

it('lets a student read the timetable but not book', function () {
    actingAs($this->student);

    livewire(ListSchedules::class)
        ->assertOk()
        ->assertActionHidden('create');
});

it('shows the derived day and time columns', function () {
    $schedule = Schedule::factory()->create([
        'starts_at' => '2026-10-05 08:00',
        'ends_at' => '2026-10-05 10:30',
    ]);

    livewire(ListSchedules::class)
        ->assertOk()
        ->assertSee('Monday')
        ->assertSee('08:00')
        ->assertSee('10:30')
        ->assertTableColumnStateSet('duration_minutes', 150, $schedule);
});

it('rejects an overlapping booking from the form', function () {
    $section = YearSection::factory()->create();

    Schedule::factory()
        ->scheduledFor($this->teacher, $section, '2026-10-01 08:00', '2026-10-01 10:00')
        ->create();

    livewire(CreateSchedule::class)
        ->fillForm([
            'title' => 'Clashing class',
            'teacher_id' => $this->teacher->id,
            'year_section_id' => $section->id,
            'starts_at' => '2026-10-01 09:00',
            'ends_at' => '2026-10-01 11:00',
            'status' => ScheduleStatus::Scheduled->value,
        ])
        ->call('create')
        ->assertHasFormErrors(['starts_at']);

    $this->assertDatabaseMissing('schedules', ['title' => 'Clashing class']);
});

it('creates a booking', function () {
    livewire(CreateSchedule::class)
        ->assertOk()
        ->fillForm([
            'title' => 'Networks',
            'teacher_id' => $this->teacher->id,
            'starts_at' => '2026-10-08 08:00',
            'ends_at' => '2026-10-08 10:00',
            'status' => ScheduleStatus::Scheduled->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('schedules', [
        'title' => 'Networks',
        'teacher_id' => $this->teacher->id,
    ]);
});

it('cancels a booking without deleting it', function () {
    $schedule = Schedule::factory()->for($this->teacher, 'teacher')->create();

    livewire(ListSchedules::class)
        ->callTableAction('cancel', $schedule)
        ->assertHasNoActionErrors();

    expect($schedule->refresh()->status)->toBe(ScheduleStatus::Cancelled)
        ->and(Schedule::query()->count())->toBe(1);
});

it('lets a teacher cancel only their own booking', function () {
    $otherTeacher = User::factory()->teacher()->create();
    $schedule = Schedule::factory()->for($otherTeacher, 'teacher')->create();

    actingAs($this->teacher);

    livewire(ListSchedules::class)
        ->assertTableActionHidden('cancel', $schedule);
});

it('edits a booking', function () {
    $schedule = Schedule::factory()->for($this->teacher, 'teacher')->create(['title' => 'Old title']);

    livewire(EditSchedule::class, ['record' => $schedule->getKey()])
        ->assertOk()
        ->fillForm(['title' => 'New title'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($schedule->refresh()->title)->toBe('New title');
});

it('filters the timetable by status', function () {
    $scheduled = Schedule::factory()->create();
    $cancelled = Schedule::factory()->cancelled()->create();

    livewire(ListSchedules::class)
        ->assertCanSeeTableRecords([$scheduled, $cancelled])
        ->filterTable('status', ScheduleStatus::Cancelled->value)
        ->assertCanSeeTableRecords([$cancelled])
        ->assertCanNotSeeTableRecords([$scheduled]);
});

/*
|--------------------------------------------------------------------------
| Class session resource
|--------------------------------------------------------------------------
*/

it('lists class sessions', function () {
    $sessions = ClassSession::factory()->count(2)->completed()->create();

    livewire(ListClassSessions::class)
        ->assertOk()
        ->assertCanSeeTableRecords($sessions);
});

it('lets a student see the running class but not start one', function () {
    actingAs($this->student);

    livewire(ListClassSessions::class)
        ->assertOk()
        ->assertActionHidden('create');
});

it('starts a class from a booking', function () {
    $schedule = Schedule::factory()->for($this->teacher, 'teacher')->create(['title' => 'Databases']);

    livewire(CreateClassSession::class)
        ->assertOk()
        ->fillForm(['schedule_id' => $schedule->id])
        ->call('create')
        ->assertHasNoFormErrors();

    $session = ClassSession::query()->sole();

    expect($session->schedule_id)->toBe($schedule->id)
        ->and($session->status)->toBe(ClassSessionStatus::Active);
});

it('refuses to start a second class while one runs', function () {
    $schedule = Schedule::factory()->create();

    ClassSession::factory()->create();

    livewire(CreateClassSession::class)
        ->fillForm(['schedule_id' => $schedule->id])
        ->call('create')
        ->assertHasFormErrors(['schedule_id']);
});

it('ends a running class from the list', function () {
    $session = ClassSession::factory()->for($this->teacher, 'startedBy')->create();

    livewire(ListClassSessions::class)
        ->callTableAction('end', $session)
        ->assertHasNoActionErrors();

    expect($session->refresh()->status)->toBe(ClassSessionStatus::Completed)
        ->and($session->ended_at)->not->toBeNull();
});

it('hides the end action from a teacher who did not start the class', function () {
    $otherTeacher = User::factory()->teacher()->create();
    $session = ClassSession::factory()->for($otherTeacher, 'startedBy')->create();

    actingAs($this->teacher);

    livewire(ListClassSessions::class)
        ->assertTableActionHidden('end', $session);
});

it('offers no end action on a class that already finished', function () {
    $session = ClassSession::factory()->completed()->create();

    livewire(ListClassSessions::class)
        ->assertTableActionHidden('end', $session);
});

it('shows a session with its attendee count', function () {
    $session = ClassSession::factory()->create();

    $session->recordAttendance($this->student);
    $session->recordAttendance(User::factory()->student()->create());

    livewire(ViewClassSession::class, ['record' => $session->getKey()])
        ->assertOk()
        ->assertSee($session->schedule->title);
});

/*
|--------------------------------------------------------------------------
| Attendance roster
|--------------------------------------------------------------------------
*/

it('lists the roster for a session', function () {
    $session = ClassSession::factory()->for($this->teacher, 'startedBy')->create();

    $session->recordAttendance($this->student);
    $session->recordAttendance(User::factory()->student()->create(['first_name' => 'Grace', 'last_name' => 'Hopper']));

    livewire(AttendancesRelationManager::class, [
        'ownerRecord' => $session,
        'pageClass' => ViewClassSession::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords($session->attendance);
});

it('lets the teacher who started the session open it', function () {
    $session = ClassSession::factory()->for($this->teacher, 'startedBy')->create();

    actingAs($this->teacher);

    livewire(ViewClassSession::class, ['record' => $session->getKey()])
        ->assertOk()
        ->assertSeeLivewire(AttendancesRelationManager::class);
});

it('keeps the roster read only', function () {
    $session = ClassSession::factory()->for($this->teacher, 'startedBy')->create();

    livewire(AttendancesRelationManager::class, [
        'ownerRecord' => $session,
        'pageClass' => ViewClassSession::class,
    ])
        ->assertOk()
        ->assertActionDoesNotExist('create')
        ->assertActionDoesNotExist('delete');
});

it('refuses the roster to a student', function () {
    $session = ClassSession::factory()->for($this->teacher, 'startedBy')->create();

    actingAs($this->student);

    livewire(ViewClassSession::class, ['record' => $session->getKey()])
        ->assertForbidden();
});

it('refuses the roster to a teacher who did not start the session', function () {
    $session = ClassSession::factory()->for($this->teacher, 'startedBy')->create();

    $otherTeacher = User::factory()->teacher()->create();
    actingAs($otherTeacher);

    livewire(ViewClassSession::class, ['record' => $session->getKey()])
        ->assertForbidden();
});
