<?php

use App\Models\Attendance;
use App\Models\ClassSession;
use App\Models\Pc;
use App\Models\PcSession;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Shorthand for Livewire::test(), matching the helper name used throughout the
 * Filament documentation.
 *
 * Defined here rather than pulling in pestphp/pest-plugin-livewire, which is not
 * currently a dependency.
 */
function livewire(string $name, array $params = []): Testable
{
    return Livewire::test($name, $params);
}

/**
 * A tiny but genuine JPEG, used by the Live Screens tests.
 *
 * Real bytes rather than a stub string, because the frame endpoint validates on a
 * JPEG magic marker — a fake "fake image" body would fail the check it is meant to
 * be passing.
 */
function jpegBytes(): string
{
    return base64_decode(
        '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRof'
        .'Hh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwh'
        .'MjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIy'
        .'MjL/wAARCAABAAEDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcI'
        .'CQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwR'
        .'VS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlq'
        .'c3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJyt'
        .'LT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/9oACAEBAAA/APn+iiigD//Z'
    );
}

/**
 * Build a running class with a teacher, a student, and the PC that student is
 * sitting at — the arrangement Live Screens scopes its grid to.
 *
 * @return array{0: ClassSession, 1: User, 2: Pc}
 */
function runningClassWithOccupiedPc(User $teacher, User $student): array
{
    $schedule = Schedule::factory()->create([
        'teacher_id' => $teacher->getKey(),
        'starts_at' => now()->subMinutes(10),
        'ends_at' => now()->addMinutes(50),
    ]);

    $session = ClassSession::factory()->create([
        'schedule_id' => $schedule->getKey(),
        'started_by_id' => $teacher->getKey(),
        'started_at' => now()->subMinutes(5),
    ]);

    $pc = Pc::factory()->create();

    Attendance::factory()->create([
        'class_session_id' => $session->getKey(),
        'user_id' => $student->getKey(),
    ]);

    PcSession::factory()->create([
        'pc_id' => $pc->getKey(),
        'user_id' => $student->getKey(),
    ]);

    return [$session, $student, $pc];
}
