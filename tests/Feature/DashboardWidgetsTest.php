<?php

use App\Enums\PcStatus;
use App\Filament\Student\Widgets\MyStorage;
use App\Filament\Student\Widgets\StudentStatus;
use App\Filament\Student\Widgets\StudentWelcome;
use App\Filament\Widgets\LabOverview;
use App\Filament\Widgets\PcStatusChart;
use App\Models\GroupChat;
use App\Models\Pc;
use App\Models\Schedule;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\ClassSessionService;
use Filament\Pages\Dashboard;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->teacher = User::factory()->teacher()->create();
    $this->student = User::factory()->student()->create();
});

/**
 * Widgets are nested Livewire components, so the dashboard's initial HTML contains
 * each widget's *reference*, not its rendered figures. Assertions about numbers
 * therefore render the widget itself; assertions about which widgets appear on a
 * dashboard check for the reference in the page.
 */

// -----------------------------------------------------------------
// Who sees what
// -----------------------------------------------------------------

it('shows staff widgets only to staff', function () {
    $this->actingAs($this->admin);
    expect(LabOverview::canView())->toBeTrue()
        ->and(PcStatusChart::canView())->toBeTrue();

    $this->actingAs($this->teacher);
    expect(LabOverview::canView())->toBeTrue();

    $this->actingAs($this->student);
    expect(LabOverview::canView())->toBeFalse()
        ->and(PcStatusChart::canView())->toBeFalse();
});

it('shows student widgets only to students', function () {
    $this->actingAs($this->student);
    expect(MyStorage::canView())->toBeTrue()
        ->and(StudentStatus::canView())->toBeTrue();

    $this->actingAs($this->admin);
    expect(MyStorage::canView())->toBeFalse()
        ->and(StudentStatus::canView())->toBeFalse();
});

// -----------------------------------------------------------------
// Lab overview
// -----------------------------------------------------------------

it('counts PCs by status', function () {
    Pc::factory()->count(2)->create(['status' => PcStatus::Online]);
    Pc::factory()->count(3)->create(['status' => PcStatus::Offline]);
    Pc::factory()->create(['status' => PcStatus::Maintenance]);

    $this->actingAs($this->admin);

    livewire(LabOverview::class)
        ->assertSee('PCs online', '2 / 6')
        ->assertSee('PCs offline', '3')
        ->assertSee('In maintenance', '1')
        ->assertSee('Total inventory', '6');
});

it('reports no class in progress when nothing is running', function () {
    $this->actingAs($this->admin);

    livewire(LabOverview::class)
        ->assertSee('None')
        ->assertSee('Start one from a schedule');
});

it('names the running class and how many students are on its roster', function () {
    $schedule = Schedule::factory()->for($this->teacher, 'teacher')->create(['title' => 'Data Structures']);
    $session = app(ClassSessionService::class)->start($schedule, $this->teacher);

    $session->recordAttendance($this->student);

    $this->actingAs($this->admin);

    livewire(LabOverview::class)
        ->assertSee('Data Structures')
        ->assertSee('1 / 1');
});

it('handles a lab with no PCs registered', function () {
    $this->actingAs($this->admin);

    livewire(LabOverview::class)
        ->assertSee('0 / 0')
        ->assertSee('No PCs registered');
});

// -----------------------------------------------------------------
// PC status chart
// -----------------------------------------------------------------

it('shows every status in the chart heading', function () {
    Pc::factory()->count(3)->create(['status' => PcStatus::Online]);

    $this->actingAs($this->admin);

    livewire(PcStatusChart::class)
        ->assertSee('PC status (3)');
});

// -----------------------------------------------------------------
// Student widgets
// -----------------------------------------------------------------

it('reports storage against the quota', function () {
    $this->student->forceFill(['storage_quota_mb' => 10])->save();

    StoredFile::factory()->create([
        'user_id' => $this->student->id,
        'size' => 2 * 1024 * 1024,
    ]);

    // storage_used_mb is a cached counter that FileService maintains; the widget
    // deliberately reads it rather than re-summing on every dashboard load.
    $this->student->recalculateStorageUsed();

    $this->actingAs($this->student->fresh());

    livewire(MyStorage::class)
        ->assertSee('Used')
        ->assertSee('2 MB')
        ->assertSee('20% of 10 MB');
});

it('warns as the quota runs out', function () {
    $this->student->forceFill(['storage_quota_mb' => 1])->save();

    StoredFile::factory()->create([
        'user_id' => $this->student->id,
        'size' => 1024 * 1024,
    ]);

    $this->student->recalculateStorageUsed();

    $this->actingAs($this->student->fresh());

    livewire(MyStorage::class)
        ->assertSee('100% of 1 MB')
        ->assertSee('0 B');
});

it('counts the group chats a student has joined', function () {
    $this->actingAs($this->student);

    livewire(StudentStatus::class)->assertSee('0 joined');

    $group = GroupChat::factory()->create();
    $group->join($this->student);

    $this->actingAs($this->student);

    livewire(StudentStatus::class)->assertSee('1 joined');
});

it('tells a student whether a class is running and whether they joined', function () {
    $this->actingAs($this->student);

    livewire(StudentStatus::class)
        ->assertSee('No class running')
        ->assertSee('Nothing to join right now');

    $schedule = Schedule::factory()->for($this->teacher, 'teacher')->create(['title' => 'Web Development']);
    $session = app(ClassSessionService::class)->start($schedule, $this->teacher);

    $this->actingAs($this->student);
    livewire(StudentStatus::class)
        ->assertSee('Web Development')
        ->assertSee('Press Join class to record your attendance');

    $session->recordAttendance($this->student);

    $this->actingAs($this->student);
    livewire(StudentStatus::class)
        ->assertSee('You have joined this class');
});

// -----------------------------------------------------------------
// The dashboards themselves
// -----------------------------------------------------------------

it('puts the lab widgets on the admin dashboard', function () {
    $html = $this->actingAs($this->admin)
        ->get(Dashboard::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->getContent();

    expect($html)->toContain('LabOverview')
        ->toContain('PcStatusChart');
});

it('keeps the lab widgets off the student dashboard', function () {
    $html = $this->actingAs($this->student)
        ->get(Dashboard::getUrl(panel: 'student'))
        ->assertSuccessful()
        ->getContent();

    expect($html)->toContain('MyStorage')
        ->toContain('StudentStatus')
        // PC inventory is not a student's business.
        ->not->toContain('LabOverview')
        ->not->toContain('PcStatusChart');
});

it('greets a student by time of day and shows their storage', function () {
    $html = $this->actingAs($this->student)
        ->get(Dashboard::getUrl(panel: 'student'))
        ->assertSuccessful()
        ->getContent();

    expect($html)->toContain('StudentWelcome');
});

it('shows a greeting, quick actions and recent activity', function () {
    $this->actingAs($this->student);

    // Content assertions target the widget itself: the dashboard's initial HTML
    // holds each widget's Livewire reference, not its rendered body.
    livewire(StudentWelcome::class)
        ->assertSee('Quick actions')
        ->assertSee('Recent activity')
        ->assertSee('Nothing yet')
        ->assertSee($this->student->first_name);
});

it('links to every student page from the dashboard', function () {
    $this->actingAs($this->student);

    $html = livewire(StudentWelcome::class)->html();

    // No /student/messages: direct messaging was removed and group chat replaced it.
    expect($html)->toContain('/student/my-drive')
        ->toContain('/student/group-chat')
        ->toContain('/student/my-attendance')
        ->toContain('/student/join-class')
        ->not->toContain('/student/messages');
});

it('shows a file in the recent activity list', function () {
    StoredFile::factory()->create([
        'user_id' => $this->student->id,
        'name' => 'revision-notes.txt',
    ]);

    $this->actingAs($this->student);

    livewire(StudentWelcome::class)->assertSee('revision-notes.txt');
});
