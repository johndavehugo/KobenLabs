<?php

use App\Filament\Student\Pages\MyAttendance;
use App\Models\ClassSession;
use App\Models\Schedule;
use App\Models\User;
use App\Models\YearSection;
use App\Services\AttendanceSummaryService;
use App\Services\ClassSessionService;

beforeEach(function () {
    $this->service = app(AttendanceSummaryService::class);
    $this->teacher = User::factory()->teacher()->create();
    $this->student = User::factory()->student()->create();
});

/**
 * Attendance rows record presence only. There is no row for a student who did not
 * join, so every "how often was I absent" figure has to be derived by comparing
 * attendance against the sessions held for that student's year and section. These
 * tests pin that derivation, including the case where it has no denominator.
 */
/**
 * Starts one session and returns it still running.
 *
 * Only one class session may be active at a time (ClassSessionObserver), so a test
 * that needs several must end each before starting the next. The schedules are also
 * spaced a day apart, because ScheduleObserver refuses overlapping bookings for the
 * same section.
 */
function startSession(User $teacher, $section, string $title = 'Data Structures', int $day = 0): ClassSession
{
    $startsAt = now()->addDays($day + 1)->setTime(9, 0);

    $schedule = Schedule::factory()->for($teacher, 'teacher')->create([
        'title' => $title,
        'year_section_id' => $section?->id,
        'starts_at' => $startsAt,
        'ends_at' => (clone $startsAt)->modify('+2 hours'),
    ]);

    return app(ClassSessionService::class)->start($schedule, $teacher);
}

function endSession(ClassSession $session): ClassSession
{
    return app(ClassSessionService::class)->end($session);
}

it('counts classes held for the student year and section', function () {
    $section = $this->student->yearSection;

    endSession(startSession($this->teacher, $section, 'Data Structures', 1));
    endSession(startSession($this->teacher, $section, 'Data Structures', 2));

    // Only completed sessions count; one left running is not yet a held class.
    startSession($this->teacher, $section);

    expect($this->service->heldCount($this->student))->toBe(2);
});

it('counts the sessions the student actually attended', function () {
    $section = $this->student->yearSection;

    $attended = startSession($this->teacher, $section);
    $attended->recordAttendance($this->student);
    endSession($attended);

    expect($this->service->attendedCount($this->student))->toBe(1)
        ->and($this->service->heldCount($this->student))->toBe(1)
        ->and($this->service->rate($this->student))->toBe(100.0);
});

it('derives absence from held minus attended', function () {
    $section = $this->student->yearSection;

    $attended = startSession($this->teacher, $section);
    $attended->recordAttendance($this->student);
    endSession($attended);

    endSession(startSession($this->teacher, $section, 'Data Structures', 1));
    endSession(startSession($this->teacher, $section, 'Data Structures', 2));

    // 1 of 3. Presence-only rows mean absence can only come from the denominator.
    expect($this->service->rate($this->student))->toBe(33.3);
});

it('reports no rate rather than a misleading zero when there is no denominator', function () {
    $unassigned = User::factory()->student()->create(['year_section_id' => null]);

    expect($this->service->heldCount($unassigned))->toBe(0)
        ->and($this->service->rate($unassigned))->toBeNull();
});

it('does not count another year section\'s classes', function () {
    $otherSection = YearSection::factory()->create();

    endSession(startSession($this->teacher, $otherSection, 'Data Structures', 1));
    endSession(startSession($this->teacher, $otherSection, 'Data Structures', 2));

    expect($this->service->heldCount($this->student))->toBe(0);
});

it('breaks attendance down by subject', function () {
    $section = $this->student->yearSection;

    $algorithms = startSession($this->teacher, $section, 'Data Structures', 1);
    $algorithms->recordAttendance($this->student);
    endSession($algorithms);

    endSession(startSession($this->teacher, $section, 'Data Structures', 2));
    endSession(startSession($this->teacher, $section, 'Web Development', 3));

    $rows = $this->service->bySubject($this->student)->keyBy('subject');

    expect($rows['Data Structures']->held)->toBe(2)
        ->and($rows['Data Structures']->attended)->toBe(1)
        ->and($rows['Data Structures']->rate)->toBe(50.0)
        // Held once, attended never: a real 0%, not a missing figure.
        ->and($rows['Web Development']->held)->toBe(1)
        ->and($rows['Web Development']->attended)->toBe(0)
        ->and($rows['Web Development']->rate)->toBe(0.0);
});

it('shows a student only their own sessions', function () {
    $section = $this->student->yearSection;
    $someoneElse = User::factory()->student($section)->create();

    $mine = startSession($this->teacher, $section, 'Data Structures', 1);
    $mine->recordAttendance($this->student);
    endSession($mine);

    $theirs = startSession($this->teacher, $section, 'Data Structures', 2);
    $theirs->recordAttendance($someoneElse);
    endSession($theirs);

    $sessions = $this->service->recentSessions($this->student);

    expect($sessions)->toHaveCount(1)
        ->and($sessions->first()->id)->toBe($mine->id);
});

it('serves the attendance page to a student', function () {
    $section = $this->student->yearSection;
    $session = startSession($this->teacher, $section);
    $session->recordAttendance($this->student);
    endSession($session);

    $this->actingAs($this->student)
        ->get(MyAttendance::getUrl(panel: 'student'))
        ->assertSuccessful()
        ->assertSee('Data Structures')
        ->assertSee('100%');
});

it('does not claim a percentage when the student has no year section', function () {
    $unassigned = User::factory()->student()->create(['year_section_id' => null]);

    $this->actingAs($unassigned)
        ->get(MyAttendance::getUrl(panel: 'student'))
        ->assertSuccessful()
        ->assertSee('Not available')
        ->assertSee('not assigned to a year and section');
});

it('keeps staff off the student attendance page', function () {
    $this->actingAs($this->teacher)
        ->get(MyAttendance::getUrl(panel: 'student'))
        ->assertForbidden();
});
