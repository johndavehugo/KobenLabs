<?php

use App\Enums\ClassSessionStatus;
use App\Filament\Resources\ClassSessions\ClassSessionResource;
use App\Filament\Student\Pages\JoinClass;
use App\Models\ClassSession;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| Student panel and the join flow
|--------------------------------------------------------------------------
|
| Closes the gap where the academic loop could not be exercised in a browser:
| ClassSessionService::join() and ClassSessionPolicy::join() both existed and
| were tested, but nothing in the UI called them, so a session's roster could
| only ever be populated from a test.
|
| The footer actions live inside a Section, so they are addressed as schema
| component actions — TestAction::make('join')->schemaComponent(true).
|
*/

beforeEach(function () {
    $this->student = User::factory()->student()->create();
    $this->teacher = User::factory()->teacher()->create();
    $this->admin = User::factory()->admin()->create();
});

/**
 * The Join class button, addressed as the footer action of the page's schema.
 */
function joinAction(): TestAction
{
    return TestAction::make('join')->schemaComponent(true);
}

/*
|--------------------------------------------------------------------------
| Panel gating
|--------------------------------------------------------------------------
*/

it('serves the student panel to a student', function () {
    actingAs($this->student);

    $this->get(JoinClass::getUrl(panel: 'student'))->assertOk();
});

it('refuses the student panel to a teacher', function () {
    actingAs($this->teacher);

    $this->get(JoinClass::getUrl(panel: 'student'))->assertForbidden();
});

it('refuses the student panel to an administrator', function () {
    actingAs($this->admin);

    $this->get(JoinClass::getUrl(panel: 'student'))->assertForbidden();
});

it('refuses the student panel to a suspended account', function () {
    actingAs(User::factory()->suspended()->create());

    $this->get(JoinClass::getUrl(panel: 'student'))->assertForbidden();
});

it('still serves the admin panel to a teacher', function () {
    actingAs($this->teacher);

    $this->get(ClassSessionResource::getUrl('index'))
        ->assertOk();
});

/*
|--------------------------------------------------------------------------
| Join flow
|--------------------------------------------------------------------------
*/

it('shows nothing to join when no class is running', function () {
    actingAs($this->student);
    Filament::setCurrentPanel('student');

    livewire(JoinClass::class)
        ->assertOk()
        ->assertSee('No class running')
        ->assertActionDoesNotExist(joinAction());
});

it('offers to join a running class', function () {
    ClassSession::factory()
        ->for($this->teacher, 'startedBy')
        ->create()
        ->schedule
        ->forceFill(['title' => 'Data Structures'])
        ->saveQuietly();

    actingAs($this->student);
    Filament::setCurrentPanel('student');

    livewire(JoinClass::class)
        ->assertOk()
        ->assertSee('Data Structures')
        ->assertActionVisible(joinAction());
});

it('records attendance when the student joins', function () {
    $session = ClassSession::factory()->for($this->teacher, 'startedBy')->create();

    actingAs($this->student);
    Filament::setCurrentPanel('student');

    livewire(JoinClass::class)
        ->callAction(joinAction())
        ->assertNotified('Attendance recorded');

    expect($session->attendance()->count())->toBe(1)
        ->and($session->attendance()->first()->user_id)->toBe($this->student->id);
});

it('shows attendance recorded and hides the join button afterwards', function () {
    $session = ClassSession::factory()->for($this->teacher, 'startedBy')->create();
    $session->recordAttendance($this->student);

    actingAs($this->student);
    Filament::setCurrentPanel('student');

    livewire(JoinClass::class)
        ->assertOk()
        ->assertSee('You have joined this class')
        ->assertActionDoesNotExist(joinAction())
        ->assertActionDisabled(TestAction::make('leave')->schemaComponent(true));
});

it('does not record attendance twice', function () {
    $session = ClassSession::factory()->for($this->teacher, 'startedBy')->create();
    $session->recordAttendance($this->student);

    actingAs($this->student);
    Filament::setCurrentPanel('student');

    livewire(JoinClass::class)
        ->assertActionDoesNotExist(joinAction());

    expect($session->attendance()->count())->toBe(1);
});

it('offers nothing to join once the class has ended', function () {
    ClassSession::factory()->for($this->teacher, 'startedBy')->completed()->create();

    actingAs($this->student);
    Filament::setCurrentPanel('student');

    livewire(JoinClass::class)
        ->assertOk()
        ->assertSee('No class running')
        ->assertActionDoesNotExist(joinAction());
});

it('shows the booking details of the running class', function () {
    $session = ClassSession::factory()->for($this->teacher, 'startedBy')->create();

    $session->schedule->forceFill([
        'title' => 'Web Development',
        'starts_at' => now()->setTime(13, 0),
        'ends_at' => now()->setTime(15, 0),
    ])->saveQuietly();

    actingAs($this->student);
    Filament::setCurrentPanel('student');

    livewire(JoinClass::class)
        ->assertOk()
        ->assertSee('Web Development')
        ->assertSee('13:00')
        ->assertSee('15:00')
        ->assertSee($this->teacher->full_name);
});

it('stops offering to join once the class is marked completed', function () {
    $session = ClassSession::factory()->for($this->teacher, 'startedBy')->create();

    actingAs($this->student);
    Filament::setCurrentPanel('student');

    livewire(JoinClass::class)->assertActionVisible(joinAction());

    $session->forceFill([
        'status' => ClassSessionStatus::Completed,
        'ended_at' => now(),
    ])->saveQuietly();

    livewire(JoinClass::class)
        ->assertActionDoesNotExist(joinAction())
        ->assertSee('No class running');
});

it('keeps a teacher off the staff roster for a class they did not start', function () {
    $otherTeacher = User::factory()->teacher()->create();
    $session = ClassSession::factory()->for($otherTeacher, 'startedBy')->create();

    // A teacher may join any running class — they are in the room — but may only
    // read the roster of a class they started. These are deliberately different
    // rules, and conflating them is what let the legacy app expose every roster
    // to every authenticated user.
    expect($this->teacher->can('join', $session))->toBeTrue()
        ->and($this->teacher->can('viewAttendance', $session))->toBeFalse();

    // Their own class, though, they can read.
    $own = ClassSession::factory()->completed()->for($this->teacher, 'startedBy')->create();

    expect($this->teacher->can('viewAttendance', $own))->toBeTrue();
});
