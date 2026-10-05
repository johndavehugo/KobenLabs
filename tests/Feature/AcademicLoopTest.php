<?php

use App\Enums\ClassSessionStatus;
use App\Events\ClassSessionEnded;
use App\Events\ClassSessionStarted;
use App\Events\StudentJoinedClass;
use App\Models\ClassSession;
use App\Models\Schedule;
use App\Models\User;
use App\Models\YearSection;
use App\Services\ClassSessionService;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Schedule invariants
|--------------------------------------------------------------------------
|
| These live in ScheduleObserver rather than in a controller, so they hold for
| every write path. The legacy app checked them in ScheduleController, so a
| console write or a second route bypassed both rules entirely.
|
*/

it('rejects a booking that ends before it starts', function () {
    expect(fn () => Schedule::factory()->create([
        'starts_at' => '2026-10-01 10:00',
        'ends_at' => '2026-10-01 09:00',
    ]))->toThrow(ValidationException::class);
});

it('enforces the minimum duration', function () {
    expect(fn () => Schedule::factory()->create([
        'starts_at' => '2026-10-01 10:00',
        'ends_at' => '2026-10-01 10:10',
    ]))->toThrow(ValidationException::class);
});

it('enforces the maximum duration', function () {
    expect(fn () => Schedule::factory()->create([
        'starts_at' => '2026-10-01 08:00',
        'ends_at' => '2026-10-01 20:00',
    ]))->toThrow(ValidationException::class);
});

it('accepts a booking inside the permitted window', function () {
    $schedule = Schedule::factory()->create([
        'starts_at' => '2026-10-01 08:00',
        'ends_at' => '2026-10-01 10:00',
    ]);

    expect($schedule->duration_minutes)->toBe(120)
        ->and($schedule->is_valid_duration)->toBeTrue();
});

it('rejects a booking that overlaps another for the same teacher and section', function () {
    $teacher = User::factory()->teacher()->create();
    $section = YearSection::factory()->create();

    Schedule::factory()->scheduledFor($teacher, $section, '2026-10-01 08:00', '2026-10-01 10:00')->create();

    expect(fn () => Schedule::factory()->scheduledFor($teacher, $section, '2026-10-01 09:00', '2026-10-01 11:00')->create())
        ->toThrow(ValidationException::class);
});

it('names the clashing booking in the error', function () {
    $teacher = User::factory()->teacher()->create();
    $section = YearSection::factory()->create();

    Schedule::factory()->scheduledFor($teacher, $section, '2026-10-01 08:00', '2026-10-01 10:00')
        ->create(['title' => 'Data Structures']);

    try {
        Schedule::factory()->scheduledFor($teacher, $section, '2026-10-01 09:00', '2026-10-01 11:00')->create();

        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['starts_at'][0])->toContain('Data Structures');
    }
});

it('allows a cancelled booking to give up its slot', function () {
    $teacher = User::factory()->teacher()->create();
    $section = YearSection::factory()->create();

    Schedule::factory()->cancelled()
        ->scheduledFor($teacher, $section, '2026-10-01 08:00', '2026-10-01 10:00')
        ->create();

    $replacement = Schedule::factory()->scheduledFor($teacher, $section, '2026-10-01 09:00', '2026-10-01 11:00')->create();

    expect($replacement->exists)->toBeTrue();
});

it('allows resaving a booking without conflicting with itself', function () {
    $teacher = User::factory()->teacher()->create();
    $section = YearSection::factory()->create();

    $schedule = Schedule::factory()->scheduledFor($teacher, $section, '2026-10-01 08:00', '2026-10-01 10:00')
        ->create(['description' => 'Original']);

    $schedule->update(['description' => 'Revised']);

    expect($schedule->refresh()->description)->toBe('Revised');
});

/*
|--------------------------------------------------------------------------
| Class session service
|--------------------------------------------------------------------------
*/

it('starts a class and broadcasts it', function () {
    Event::fake([ClassSessionStarted::class]);

    $teacher = User::factory()->teacher()->create();
    $schedule = Schedule::factory()->for($teacher, 'teacher')->create(['title' => 'Web Development']);

    $session = app(ClassSessionService::class)->start($schedule, $teacher);

    expect($session->exists)->toBeTrue()
        ->and($session->status)->toBe(ClassSessionStatus::Active)
        ->and($session->started_by_id)->toBe($teacher->id);

    Event::assertDispatched(
        ClassSessionStarted::class,
        fn (ClassSessionStarted $event): bool => $event->subject === 'Web Development'
            && $event->teacherName === $teacher->full_name,
    );
});

it('refuses to start a second class while one is running', function () {
    $teacher = User::factory()->teacher()->create();

    ClassSession::factory()->create();

    expect(fn () => app(ClassSessionService::class)->start(Schedule::factory()->create(), $teacher))
        ->toThrow(ValidationException::class);
});

it('allows a new class once the previous one has ended', function () {
    $teacher = User::factory()->teacher()->create();

    ClassSession::factory()->completed()->create();

    $session = app(ClassSessionService::class)->start(Schedule::factory()->create(), $teacher);

    expect($session->exists)->toBeTrue();
});

it('ends a class once and broadcasts once', function () {
    Event::fake([ClassSessionEnded::class]);

    $service = app(ClassSessionService::class);
    $session = ClassSession::factory()->create();

    $service->end($session);
    $endedAt = $session->refresh()->ended_at;

    $service->end($session);

    expect($session->refresh()->status)->toBe(ClassSessionStatus::Completed)
        ->and($session->ended_at->timestamp)->toBe($endedAt->timestamp);

    Event::assertDispatchedTimes(ClassSessionEnded::class, 1);
});

it('records attendance once and broadcasts once', function () {
    Event::fake([StudentJoinedClass::class]);

    $service = app(ClassSessionService::class);
    $session = ClassSession::factory()->create();
    $student = User::factory()->student()->create();

    expect($service->join($session, $student))->toBeTrue()
        ->and($service->join($session, $student))->toBeFalse()
        ->and($session->attendance()->count())->toBe(1);

    Event::assertDispatchedTimes(StudentJoinedClass::class, 1);
});

it('refuses to join a class that has ended', function () {
    $service = app(ClassSessionService::class);
    $session = ClassSession::factory()->completed()->create();
    $student = User::factory()->student()->create();

    expect($service->join($session, $student))->toBeFalse()
        ->and($session->attendance()->count())->toBe(0);
});

it('surfaces the running class', function () {
    $service = app(ClassSessionService::class);

    expect($service->active())->toBeNull();

    $session = ClassSession::factory()->create();

    expect($service->active()->is($session))->toBeTrue();
});
