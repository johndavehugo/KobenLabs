<?php

use App\Enums\GroupRole;
use App\Models\AccessibilitySetting;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\ClassSession;
use App\Models\GroupChat;
use App\Models\GroupChatMessage;
use App\Models\Pc;
use App\Models\PcSession;
use App\Models\Schedule;
use App\Models\StoredFile;
use App\Models\User;
use App\Models\YearSection;
use Filament\Panel;

/*
|--------------------------------------------------------------------------
| Policy matrix
|--------------------------------------------------------------------------
|
| The legacy app had no permission matrix at all: three roles checked by a
| `requireTeacherRole()` helper copy-pasted into three controllers, plus four
| inline checks. Several capabilities had no check whatsoever.
|
| These tests pin the intended matrix. Each block states the capability, then
| asserts the outcome for student, teacher and administrator.
|
*/

/*
|--------------------------------------------------------------------------
| Users
|--------------------------------------------------------------------------
*/

it('lets staff manage accounts but denies students', function () {
    $target = User::factory()->student()->create();

    foreach (['student' => false, 'teacher' => true, 'admin' => true] as $role => $expected) {
        $actor = User::factory()->{$role}()->create();

        expect($actor->can('viewAny', User::class))->toBe($expected, "viewAny as {$role}")
            ->and($actor->can('view', $target))->toBe($expected, "view as {$role}")
            ->and($actor->can('create', [User::class]))->toBe($expected, "create as {$role}")
            ->and($actor->can('update', $target))->toBe($expected, "update as {$role}")
            ->and($actor->can('delete', $target))->toBe($expected, "delete as {$role}");
    }
});

it('lets anyone view their own account', function () {
    $user = User::factory()->student()->create();

    expect($user->can('view', $user))->toBeTrue();
});

it('prevents an administrator deleting their own account', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->student()->create();

    expect($admin->can('delete', $admin))->toBeFalse()
        ->and($admin->can('delete', $other))->toBeTrue();
});

it('prevents an administrator suspending or resetting their own account', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->teacher()->create();

    foreach (['changeStatus', 'resetPassword'] as $ability) {
        expect($admin->can($ability, $admin))->toBeFalse("{$ability} self")
            ->and($admin->can($ability, $other))->toBeTrue("{$ability} other");
    }
});

/*
|--------------------------------------------------------------------------
| Year sections
|--------------------------------------------------------------------------
*/

it('lets everyone read year sections but only staff change them', function () {
    $yearSection = YearSection::factory()->create();

    foreach (['student' => false, 'teacher' => true, 'admin' => true] as $role => $expected) {
        $actor = User::factory()->{$role}()->create();

        expect($actor->can('viewAny', YearSection::class))->toBeTrue("viewAny as {$role}")
            ->and($actor->can('view', $yearSection))->toBeTrue("view as {$role}")
            ->and($actor->can('create', [YearSection::class]))->toBe($expected, "create as {$role}")
            ->and($actor->can('update', $yearSection))->toBe($expected, "update as {$role}")
            ->and($actor->can('delete', $yearSection))->toBe($expected, "delete as {$role}");
    }
});

/*
|--------------------------------------------------------------------------
| PCs — closes a legacy hole: no role check existed at all
|--------------------------------------------------------------------------
*/

it('lets everyone read the pc inventory but only staff change it', function () {
    $pc = Pc::factory()->create();

    foreach (['student' => false, 'teacher' => true, 'admin' => true] as $role => $expected) {
        $actor = User::factory()->{$role}()->create();

        expect($actor->can('viewAny', Pc::class))->toBeTrue("viewAny as {$role}")
            ->and($actor->can('view', $pc))->toBeTrue("view as {$role}")
            ->and($actor->can('create', [Pc::class]))->toBe($expected, "create as {$role}")
            ->and($actor->can('update', $pc))->toBe($expected, "update as {$role}")
            ->and($actor->can('delete', $pc))->toBe($expected, "delete as {$role}")
            ->and($actor->can('changeStatus', $pc))->toBe($expected, "changeStatus as {$role}");
    }
});

/*
|--------------------------------------------------------------------------
| PC sessions
|--------------------------------------------------------------------------
*/

it('lets a user see and close their own pc session', function () {
    $user = User::factory()->student()->create();
    $session = PcSession::factory()->for($user)->create();
    $stranger = User::factory()->student()->create();

    expect($user->can('view', $session))->toBeTrue()
        ->and($user->can('update', $session))->toBeTrue()
        ->and($stranger->can('view', $session))->toBeFalse()
        ->and($stranger->can('update', $session))->toBeFalse()
        ->and($stranger->can('delete', $session))->toBeFalse();
});

it('lets staff see every pc session', function () {
    $session = PcSession::factory()->create();

    foreach (['teacher', 'admin'] as $role) {
        $actor = User::factory()->{$role}()->create();

        expect($actor->can('viewAny', PcSession::class))->toBeTrue("viewAny as {$role}")
            ->and($actor->can('view', $session))->toBeTrue("view as {$role}");
    }

    expect(User::factory()->student()->create()->can('viewAny', PcSession::class))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Schedules
|--------------------------------------------------------------------------
*/

it('lets everyone read schedules but only staff write them', function () {
    $schedule = Schedule::factory()->create();

    foreach (['student' => false, 'teacher' => true, 'admin' => true] as $role => $expected) {
        $actor = User::factory()->{$role}()->create();

        expect($actor->can('view', $schedule))->toBeTrue("view as {$role}")
            ->and($actor->can('create', [Schedule::class]))->toBe($expected, "create as {$role}")
            ->and($actor->can('update', $schedule))->toBe($expected, "update as {$role}")
            ->and($actor->can('delete', $schedule))->toBe($expected, "delete as {$role}");
    }
});

it('lets a teacher cancel only their own schedule', function () {
    $teacher = User::factory()->teacher()->create();
    $otherTeacher = User::factory()->teacher()->create();
    $admin = User::factory()->admin()->create();

    $own = Schedule::factory()->for($teacher, 'teacher')->create();
    $someoneElses = Schedule::factory()->for($otherTeacher, 'teacher')->create();

    expect($teacher->can('cancel', $own))->toBeTrue()
        ->and($teacher->can('cancel', $someoneElses))->toBeFalse()
        ->and($admin->can('cancel', $someoneElses))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Class sessions and attendance
|--------------------------------------------------------------------------
*/

it('lets only staff start a class session and lets only its own teacher end it', function () {
    $session = ClassSession::factory()->create();

    foreach (['student', 'teacher'] as $role) {
        $actor = User::factory()->{$role}()->create();

        // The session belongs to a different teacher, so `update` is denied
        // even for staff — see the next test for the ownership rule.
        expect($actor->can('view', $session))->toBeTrue("view as {$role}")
            ->and($actor->can('create', [ClassSession::class]))->toBe($role === 'teacher', "create as {$role}")
            ->and($actor->can('update', $session))->toBeFalse("update as {$role}");
    }

    expect(User::factory()->admin()->create()->can('update', $session))->toBeTrue('update as admin');
});

it('lets a teacher end only a session they started', function () {
    $teacher = User::factory()->teacher()->create();
    $otherTeacher = User::factory()->teacher()->create();

    // Completed, because only one active session may exist at a time and this
    // test is about the permission to end one, not about concurrency.
    $own = ClassSession::factory()->for($teacher, 'startedBy')->completed()->create();
    $someoneElses = ClassSession::factory()->for($otherTeacher, 'startedBy')->completed()->create();

    expect($teacher->can('update', $own))->toBeTrue()
        ->and($teacher->can('update', $someoneElses))->toBeFalse()
        ->and(User::factory()->admin()->create()->can('update', $someoneElses))->toBeTrue();
});

it('lets only an administrator delete a class session', function () {
    $session = ClassSession::factory()->create();

    expect(User::factory()->admin()->create()->can('delete', $session))->toBeTrue()
        ->and(User::factory()->teacher()->create()->can('delete', $session))->toBeFalse()
        ->and(User::factory()->student()->create()->can('delete', $session))->toBeFalse();
});

it('lets any authenticated user join an active session', function () {
    $active = ClassSession::factory()->create();
    $completed = ClassSession::factory()->completed()->create();

    foreach (['student', 'teacher', 'admin'] as $role) {
        $actor = User::factory()->{$role}()->create();

        expect($actor->can('join', $active))->toBeTrue("join active as {$role}")
            ->and($actor->can('join', $completed))->toBeFalse("join completed as {$role}");
    }
});

it('closes a legacy hole: a student cannot read somebody elses attendance', function () {
    $student = User::factory()->student()->create();
    $other = User::factory()->student()->create();

    $own = Attendance::factory()
        ->for(ClassSession::factory()->completed(), 'classSession')
        ->for($student)
        ->create();
    $someoneElses = Attendance::factory()
        ->for(ClassSession::factory()->completed(), 'classSession')
        ->for($other)
        ->create();

    expect($student->can('view', $own))->toBeTrue()
        ->and($student->can('view', $someoneElses))->toBeFalse()
        ->and(User::factory()->teacher()->create()->can('view', $someoneElses))->toBeTrue()
        ->and(User::factory()->admin()->create()->can('view', $someoneElses))->toBeTrue();
});

it('makes attendance immutable once written', function () {
    $attendance = Attendance::factory()
        ->for(ClassSession::factory()->completed(), 'classSession')
        ->create();

    foreach (['teacher', 'admin'] as $role) {
        $actor = User::factory()->{$role}()->create();

        expect($actor->can('update', $attendance))->toBeFalse("update as {$role}")
            ->and($actor->can('delete', $attendance))->toBeFalse("delete as {$role}");
    }
});

/*
|--------------------------------------------------------------------------
| File drives — closes a legacy hole: any ?user_id= was browsable
|--------------------------------------------------------------------------
*/

it('scopes a personal file drive to its owner, with staff access', function () {
    $owner = User::factory()->student()->create();
    $stranger = User::factory()->student()->create();
    $file = StoredFile::factory()->for($owner)->create();

    expect($owner->can('view', $file))->toBeTrue()
        ->and($owner->can('update', $file))->toBeTrue()
        ->and($owner->can('delete', $file))->toBeTrue()
        ->and($stranger->can('view', $file))->toBeFalse()
        ->and($stranger->can('update', $file))->toBeFalse()
        ->and($stranger->can('delete', $file))->toBeFalse()
        ->and(User::factory()->teacher()->create()->can('view', $file))->toBeTrue()
        ->and(User::factory()->admin()->create()->can('delete', $file))->toBeTrue();
});

it('stops staff reorganising a drive that is not theirs', function () {
    $owner = User::factory()->student()->create();
    $file = StoredFile::factory()->for($owner)->create();

    expect($owner->can('reorganise', $file))->toBeTrue()
        ->and(User::factory()->admin()->create()->can('reorganise', $file))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Group chat — closes a legacy hole: attachment downloads skipped membership
|--------------------------------------------------------------------------
*/

it('shows a group only to members and staff', function () {
    $member = User::factory()->student()->create();
    $stranger = User::factory()->student()->create();
    $group = GroupChat::factory()->create();
    $group->join($member);

    expect($member->can('view', $group))->toBeTrue()
        ->and($stranger->can('view', $group))->toBeFalse()
        ->and(User::factory()->teacher()->create()->can('view', $group))->toBeTrue();
});

it('lets only staff create a group but anyone join an active one by code', function () {
    $group = GroupChat::factory()->create();

    foreach (['student' => false, 'teacher' => true, 'admin' => true] as $role => $expected) {
        $actor = User::factory()->{$role}()->create();

        expect($actor->can('create', [GroupChat::class]))->toBe($expected, "create as {$role}")
            ->and($actor->can('join', $group))->toBeTrue("join as {$role}");
    }
});

it('refuses to join an inactive group', function () {
    $group = GroupChat::factory()->inactive()->create();

    expect(User::factory()->student()->create()->can('join', $group))->toBeFalse();
});

it('stops the creator leaving their own group', function () {
    $creator = User::factory()->teacher()->create();
    $member = User::factory()->student()->create();

    $group = GroupChat::factory()->for($creator, 'creator')->create();
    $group->join($creator);
    $group->join($member);

    expect($creator->can('leave', $group))->toBeFalse()
        ->and($member->can('leave', $group))->toBeTrue()
        ->and($member->can('delete', $group))->toBeFalse()
        ->and($creator->can('delete', $group))->toBeTrue();
});

it('gates a group message on membership', function () {
    $member = User::factory()->student()->create();
    $stranger = User::factory()->student()->create();

    $group = GroupChat::factory()->create();
    $group->join($member);

    $message = GroupChatMessage::factory()->for($group, 'groupChat')->for($member, 'sender')->create();

    expect($member->can('view', $message))->toBeTrue()
        ->and($member->can('download', $message))->toBeTrue()
        ->and($stranger->can('view', $message))->toBeFalse()
        ->and($stranger->can('download', $message))->toBeFalse()
        ->and($stranger->can('create', $message))->toBeFalse();
});

it('lets only a sender group admin or staff delete a group message', function () {
    $sender = User::factory()->student()->create();
    $group = GroupChat::factory()->create();

    $message = GroupChatMessage::factory()->for($group, 'groupChat')->for($sender, 'sender')->create();
    $system = GroupChatMessage::factory()->system()->for($group, 'groupChat')->create();

    $group->join($sender);
    $group->join(User::factory()->student()->create(), GroupRole::Admin);

    $groupAdmin = $group->members()->wherePivot('role', GroupRole::Admin->value)->first();

    expect($sender->can('delete', $message))->toBeTrue()
        ->and($sender->can('delete', $system))->toBeFalse()
        ->and($groupAdmin->can('delete', $message))->toBeTrue()
        ->and($groupAdmin->can('delete', $system))->toBeTrue()
        ->and(User::factory()->teacher()->create()->can('delete', $message))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Accessibility settings — closes a legacy hole: no ownership check
|--------------------------------------------------------------------------
*/

it('restricts accessibility settings to their owner, staff included', function () {
    $owner = User::factory()->student()->create();
    $stranger = User::factory()->student()->create();
    $settings = AccessibilitySetting::for($owner);

    expect($owner->can('view', $settings))->toBeTrue()
        ->and($owner->can('update', $settings))->toBeTrue()
        ->and($stranger->can('view', $settings))->toBeFalse()
        ->and($stranger->can('update', $settings))->toBeFalse()
        ->and(User::factory()->admin()->create()->can('update', $settings))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Audit log — append only
|--------------------------------------------------------------------------
*/

it('lets staff read the audit log but nobody change it', function () {
    $log = AuditLog::record('pc.updated');

    expect(User::factory()->teacher()->create()->can('viewAny', AuditLog::class))->toBeTrue()
        ->and(User::factory()->admin()->create()->can('view', $log))->toBeTrue()
        ->and(User::factory()->student()->create()->can('viewAny', AuditLog::class))->toBeFalse();

    foreach (['create', 'update', 'delete'] as $ability) {
        expect(User::factory()->admin()->create()->can($ability, $log))->toBeFalse("{$ability} as admin");
    }
});

/*
|--------------------------------------------------------------------------
| Panel access
|--------------------------------------------------------------------------
*/

it('gates each panel by role', function () {
    $student = User::factory()->student()->create();
    $teacher = User::factory()->teacher()->create();
    $admin = User::factory()->admin()->create();

    expect($teacher->canAccessPanel(Panel::make()->id('admin')))->toBeTrue()
        ->and($admin->canAccessPanel(Panel::make()->id('admin')))->toBeTrue()
        ->and($student->canAccessPanel(Panel::make()->id('admin')))->toBeFalse()
        ->and($student->canAccessPanel(Panel::make()->id('student')))->toBeTrue()
        ->and($teacher->canAccessPanel(Panel::make()->id('student')))->toBeFalse();
});

it('refuses panel access to a suspended account', function () {
    $suspended = User::factory()->suspended()->create();

    expect($suspended->canAccessPanel(Panel::make()->id('admin')))->toBeFalse();
});
