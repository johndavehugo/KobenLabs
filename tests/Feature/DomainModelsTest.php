<?php

use App\Enums\ClassSessionStatus;
use App\Enums\GroupMessageType;
use App\Enums\GroupRole;
use App\Enums\PcSessionStatus;
use App\Enums\ScheduleStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AccessibilitySetting;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\ClassSession;
use App\Models\GroupChat;
use App\Models\GroupChatMessage;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Pc;
use App\Models\PcSession;
use App\Models\Schedule;
use App\Models\StoredFile;
use App\Models\User;
use App\Models\YearSection;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Domain model smoke tests
|--------------------------------------------------------------------------
|
| These verify the shape of the migrated schema: that every model maps to its
| table, that enum casts round-trip, and that the relationships resolve. They
| are deliberately shallow — behaviour belongs in dedicated test files.
|
*/

it('casts user role and status to enums', function () {
    $user = User::factory()->student()->create();

    expect($user->role)->toBe(UserRole::Student)
        ->and($user->status)->toBe(UserStatus::Active)
        ->and($user->canAuthenticate())->toBeTrue()
        ->and($user->isStudent())->toBeTrue()
        ->and($user->full_name)->toBe("{$user->first_name} {$user->last_name}");
});

it('treats teachers and admins as staff', function () {
    expect(User::factory()->teacher()->create()->canManageLab())->toBeTrue()
        ->and(User::factory()->admin()->create()->canManageLab())->toBeTrue()
        ->and(User::factory()->student()->create()->canManageLab())->toBeFalse();
});

it('blocks inactive and suspended accounts from authenticating', function () {
    expect(User::factory()->inactive()->create()->canAuthenticate())->toBeFalse()
        ->and(User::factory()->suspended()->create()->canAuthenticate())->toBeFalse();
});

it('links a student to a year and section', function () {
    $section = YearSection::factory()->create(['year' => 'BSIT 2', 'section' => 'B']);
    $student = User::factory()->student($section)->create();

    expect($student->yearSection->is($section))->toBeTrue()
        ->and($student->yearSection->label)->toBe('BSIT 2 - B')
        ->and($section->students()->count())->toBe(1);
});

it('detects a pc by ip address and ignores loopback', function () {
    $pc = Pc::factory()->create(['ip_address' => '192.168.1.50']);

    expect(Pc::detectFromRequest('192.168.1.50'))?->is($pc)->toBeTrue()
        ->and(Pc::detectFromRequest('127.0.0.1'))->toBeNull()
        ->and(Pc::detectFromRequest('::1'))->toBeNull();
});

it('exempts maintenance pcs from the stale sweep', function () {
    $threshold = (int) config('lab.pcs.stale_after_minutes');

    expect(Pc::factory()->stale()->create()->isStale($threshold))->toBeTrue()
        ->and(Pc::factory()->online()->create()->isStale($threshold))->toBeFalse()
        ->and(Pc::factory()->maintenance()->create()->isStale($threshold))->toBeFalse();
});

it('normalises and validates mac addresses', function () {
    expect(Pc::normaliseMac('aa-bb-cc-dd-ee-ff'))->toBe('AA:BB:CC:DD:EE:FF')
        ->and(Pc::normaliseMac(null))->toBeNull()
        ->and(Pc::isValidMac('AA:BB:CC:DD:EE:FF'))->toBeTrue()
        ->and(Pc::isValidMac('not-a-mac'))->toBeFalse();
});

it('treats back to back bookings as non conflicting', function () {
    $teacher = User::factory()->teacher()->create();
    $section = YearSection::factory()->create();

    Schedule::factory()->scheduledFor($teacher, $section, '2026-10-01 08:00', '2026-10-01 10:00')->create();
    $backToBack = Schedule::factory()->scheduledFor($teacher, $section, '2026-10-01 10:00', '2026-10-01 12:00')->create();

    // The overlap window is half-open, so touching endpoints do not clash.
    expect($backToBack->conflictsWith())->toHaveCount(0);
});

it('detects partially overlapping schedules', function () {
    $teacher = User::factory()->teacher()->create();
    $section = YearSection::factory()->create();

    Schedule::factory()->scheduledFor($teacher, $section, '2026-10-01 08:00', '2026-10-01 10:00')->create();

    // Built unsaved: the candidate is never persisted, so the observer does not
    // reject it. This exercises the predicate, which the observer enforces.
    $candidate = new Schedule([
        'teacher_id' => $teacher->id,
        'year_section_id' => $section->id,
        'starts_at' => '2026-10-01 09:00',
        'ends_at' => '2026-10-01 11:00',
    ]);

    expect($candidate->conflictsWith())->toHaveCount(1)
        ->and($candidate->overlaps($candidate))->toBeTrue();
});

it('excludes cancelled schedules from conflict detection', function () {
    $teacher = User::factory()->teacher()->create();
    $section = YearSection::factory()->create();

    Schedule::factory()->cancelled()
        ->scheduledFor($teacher, $section, '2026-10-01 09:00', '2026-10-01 11:00')
        ->create();

    $candidate = Schedule::factory()->scheduledFor($teacher, $section, '2026-10-01 08:00', '2026-10-01 10:00')->create();

    expect($candidate->conflictsWith())->toHaveCount(0);
});

it('scopes conflict detection to the same teacher and section', function () {
    $teacher = User::factory()->teacher()->create();
    $otherTeacher = User::factory()->teacher()->create();
    $otherSection = YearSection::factory()->create();
    $section = YearSection::factory()->create();

    // Same time, but owned by a different teacher and a different section.
    Schedule::factory()->scheduledFor($otherTeacher, $section, '2026-10-01 08:00', '2026-10-01 10:00')->create();
    Schedule::factory()->scheduledFor($teacher, $otherSection, '2026-10-01 08:00', '2026-10-01 10:00')->create();
    Schedule::factory()->scheduledFor($teacher, $section, '2026-10-01 08:00', '2026-10-01 10:00')->create();

    $candidate = new Schedule([
        'teacher_id' => $teacher->id,
        'year_section_id' => $section->id,
        'starts_at' => '2026-10-01 09:00',
        'ends_at' => '2026-10-01 11:00',
    ]);

    expect($candidate->conflictsWith())->toHaveCount(1);
});

it('never reports a schedule as conflicting with itself', function () {
    $schedule = Schedule::factory()->create([
        'starts_at' => '2026-10-01 08:00',
        'ends_at' => '2026-10-01 10:00',
    ]);

    expect($schedule->conflictsWith())->toHaveCount(0);
});

it('derives schedule view fields from timestamps', function () {
    $schedule = Schedule::factory()->create([
        'starts_at' => '2026-10-05 08:00',
        'ends_at' => '2026-10-05 10:30',
    ]);

    expect($schedule->day_of_week)->toBe('Monday')
        ->and($schedule->start_time)->toBe('08:00')
        ->and($schedule->end_time)->toBe('10:30')
        ->and($schedule->duration_minutes)->toBe(150)
        ->and($schedule->is_valid_duration)->toBeTrue()
        ->and($schedule->status)->toBe(ScheduleStatus::Scheduled);
});

it('records attendance idempotently', function () {
    $session = ClassSession::factory()->create();
    $student = User::factory()->student()->create();

    expect($session->recordAttendance($student))->toBeTrue()
        ->and($session->recordAttendance($student))->toBeFalse()
        ->and($session->attendance()->count())->toBe(1)
        ->and($student->attendedSessions()->count())->toBe(1)
        ->and(Attendance::query()->count())->toBe(1);
});

it('ends a class session once', function () {
    $session = ClassSession::factory()->create();

    expect($session->isActive())->toBeTrue();

    $session->end();
    $endedAt = $session->ended_at;
    $session->end();

    expect($session->refresh()->status)->toBe(ClassSessionStatus::Completed)
        ->and($session->ended_at->timestamp)->toBe($endedAt->timestamp);
});

it('closes a pc session and derives its duration', function () {
    $session = PcSession::factory()->create(['started_at' => now()->subMinutes(45)]);

    $session->close();

    expect($session->status)->toBe(PcSessionStatus::Completed)
        ->and($session->duration_minutes)->toBe(45)
        ->and($session->ended_at)->not->toBeNull();
});

it('cascades a folder delete through the tree and onto disk', function () {
    Storage::fake(config('lab.storage.default_disk'));

    $user = User::factory()->student()->create();
    $folder = StoredFile::factory()->folder()->for($user)->create();

    $child = StoredFile::factory()->inside($folder)->create(['path' => 'users/1/child.txt']);
    Storage::disk(config('lab.storage.default_disk'))->put($child->path, 'contents');

    $grandchild = StoredFile::factory()->inside($child)->create(['path' => 'users/1/grandchild.txt']);
    Storage::disk(config('lab.storage.default_disk'))->put($grandchild->path, 'contents');

    expect($folder->descendantIds())->toHaveCount(2);

    $removed = $folder->deleteTree();

    $disk = Storage::disk(config('lab.storage.default_disk'));

    expect($removed)->toBe(3)
        ->and(StoredFile::query()->count())->toBe(0)
        ->and($disk->exists($child->path))->toBeFalse()
        ->and($disk->exists($grandchild->path))->toBeFalse();
});

it('builds a breadcrumb chain for a nested file', function () {
    $user = User::factory()->student()->create();
    $root = StoredFile::factory()->folder()->for($user)->create(['name' => 'Documents']);
    $child = StoredFile::factory()->folder()->for($user)->create(['name' => 'Reports']);
    $child->update(['parent_id' => $root->id]);
    $file = StoredFile::factory()->inside($child)->create();

    expect($file->ancestors()->pluck('name')->all())->toBe(['Documents', 'Reports']);
});

it('recomputes the storage counter from the file tree', function () {
    $user = User::factory()->student()->create();

    StoredFile::factory()->for($user)->create(['size' => 2 * 1024 * 1024]);
    StoredFile::factory()->for($user)->create(['size' => 3 * 1024 * 1024]);
    StoredFile::factory()->folder()->for($user)->create();

    $user->recalculateStorageUsed();

    expect((float) $user->refresh()->storage_used_mb)->toBe(5.0)
        ->and($user->storageRemainingBytes())->toBe($user->storageQuotaBytes() - 5 * 1024 * 1024);
});

it('tracks message read state through the recipient pivot', function () {
    $message = Message::factory()->create();
    $recipient = User::factory()->student()->create();

    $message->sendTo([$recipient->id]);

    expect($recipient->unreadMessages()->count())->toBe(1);

    $message->markReadBy($recipient);

    expect($recipient->unreadMessages()->count())->toBe(0)
        ->and($recipient->receivedMessages()->count())->toBe(1);
});

it('exposes message attachments', function () {
    $message = Message::factory()->create();
    MessageAttachment::factory()->for($message)->create();

    expect($message->attachments)->toHaveCount(1)
        ->and($message->attachments->first()->original_name)->toEndWith('.pdf');
});

it('manages group membership with a group scoped role', function () {
    $group = GroupChat::factory()->create();
    $student = User::factory()->student()->create();
    $otherAdmin = User::factory()->admin()->create();

    $group->join($student, GroupRole::Admin);

    expect($group->isMember($student))->toBeTrue()
        ->and($group->roleFor($student))->toBe(GroupRole::Admin)
        ->and($group->roleFor($otherAdmin))->toBeNull()
        ->and($student->groupChats()->count())->toBe(1);

    $group->leave($student);

    expect($group->isMember($student))->toBeFalse();
});

it('creates system messages without a sender', function () {
    $group = GroupChat::factory()->create();

    $message = $group->systemMessage('Alice joined the group');

    expect($message->sender_id)->toBeNull()
        ->and($message->type)->toBe(GroupMessageType::System)
        ->and($message->isSystem())->toBeTrue()
        ->and($message->hasAttachment())->toBeFalse();
});

it('records read receipts on group messages', function () {
    $message = GroupChatMessage::factory()->create();
    $reader = User::factory()->student()->create();

    $message->markReadBy($reader);

    expect($message->readBy()->count())->toBe(1)
        ->and($message->readBy()->first()->pivot->read_at)->not->toBeNull();
});

it('generates unique join codes', function () {
    $codes = collect(range(1, 25))
        ->map(fn () => GroupChat::generateJoinCode())
        ->all();

    expect($codes)->toHaveCount(25)
        ->and(array_unique($codes))->toHaveCount(25)
        ->and(GroupChat::query()->count())->toBe(0);
});

it('creates default accessibility settings on demand', function () {
    $user = User::factory()->create();

    expect($user->accessibilitySettings)->toBeNull();

    $settings = AccessibilitySetting::for($user);

    expect($settings->exists)->toBeTrue()
        ->and($settings->text_size->value)->toBe('normal')
        ->and($settings->user->is($user))->toBeTrue();
});

it('records an audit log with request context', function () {
    $user = User::factory()->admin()->create();
    $pc = Pc::factory()->create();

    $log = AuditLog::record('pc.updated', $pc, ['status' => 'maintenance'], $user->id);

    expect($log->action)->toBe('pc.updated')
        ->and($log->auditable_type)->toBe(Pc::class)
        ->and($log->auditable_id)->toBe($pc->id)
        ->and($log->properties)->toBe(['status' => 'maintenance'])
        ->and($log->user->is($user))->toBeTrue()
        ->and($log->created_at)->not->toBeNull();
});

it('seeds a small academic structure without collisions', function () {
    $section = YearSection::factory()->create(['year' => 'BSIT 1', 'section' => 'A']);
    $teacher = User::factory()->teacher()->create();
    $schedule = Schedule::factory()->scheduledFor($teacher, $section, '2026-10-06 08:00', '2026-10-06 10:00')->create();
    $session = ClassSession::factory()->for($schedule)->for($teacher, 'startedBy')->create();
    $student = User::factory()->student($section)->create();

    $session->recordAttendance($student);

    expect($schedule->teacher->is($teacher))->toBeTrue()
        ->and($schedule->yearSection->is($section))->toBeTrue()
        ->and($session->startedBy->is($teacher))->toBeTrue()
        ->and($session->attendees()->first()->is($student))->toBeTrue()
        ->and($student->attendedSessions()->first()->pivot->joined_at)->not->toBeNull();
});
