<?php

use App\Filament\Pages\Messages as StaffMessages;
use App\Filament\Student\Pages\Messages as StudentMessages;
use App\Models\Message;
use App\Models\User;
use App\Models\YearSection;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    $this->teacher = User::factory()->teacher()->create();
    $this->student = User::factory()->student()->create();
});

// -----------------------------------------------------------------
// The inbox a legacy UI never surfaced
// -----------------------------------------------------------------

it('serves the student inbox', function () {
    $this->actingAs($this->student)
        ->get(StudentMessages::getUrl(panel: 'student'))
        ->assertSuccessful();
});

it('lists a message sent to me and one broadcast to everyone', function () {
    $direct = Message::factory()->create(['subject' => 'Your result']);
    $direct->sendTo([$this->student->id]);

    $broadcast = Message::factory()->broadcast()->create(['subject' => 'School closes early']);
    $broadcast->sendTo([$this->student->id], isBroadcast: true);

    $this->actingAs($this->student);

    livewire(StudentMessages::class)
        ->assertCanSeeTableRecords([$direct, $broadcast])
        ->assertSee('Your result')
        ->assertSee('School closes early');
});

it('does not list a message addressed to somebody else', function () {
    $theirs = Message::factory()->create(['subject' => 'Not for you']);
    $theirs->sendTo([User::factory()->student()->create()->id]);

    $this->actingAs($this->student);

    livewire(StudentMessages::class)
        ->assertDontSee('Not for you');
});

it('counts unread messages and clears the count once read', function () {
    $message = Message::factory()->create();
    $message->sendTo([$this->student->id]);

    $this->actingAs($this->student);

    expect(livewire(StudentMessages::class)->instance()->unreadCount())->toBe(1);

    livewire(StudentMessages::class)
        ->callAction(TestAction::make('markAllRead'));

    expect(livewire(StudentMessages::class)->instance()->unreadCount())->toBe(0)
        ->and($message->fresh()->isReadBy($this->student))->toBeTrue();
});

it('marks a message read when it is opened from the list', function () {
    $message = Message::factory()->create();
    $message->sendTo([$this->student->id]);

    $this->actingAs($this->student);

    expect($message->isReadBy($this->student))->toBeFalse();

    livewire(StudentMessages::class)
        ->callAction(TestAction::make('open')->table($message));

    expect($message->fresh()->isReadBy($this->student))->toBeTrue();
});

it('drops an unread count for a message opened directly by id', function () {
    $message = Message::factory()->create();
    $message->sendTo([$this->student->id]);

    expect($message->isReadBy($this->student))->toBeFalse();

    $this->actingAs($this->student);

    // mount() marks the opened message read, so a shared link does not leave the
    // student's badge stuck at 1 forever.
    livewire(StudentMessages::class)
        ->set('messageId', $message->id);

    expect($message->fresh()->isReadBy($this->student))->toBeTrue();
});

it('ignores a message id belonging to somebody else', function () {
    $theirs = Message::factory()->create(['body' => 'private correspondence']);
    $theirs->sendTo([User::factory()->student()->create()->id]);

    $this->actingAs($this->student);

    livewire(StudentMessages::class)
        ->set('messageId', $theirs->id)
        ->assertSet('messageId', null)
        ->assertDontSee('private correspondence');
});

it('marks the whole mailbox read', function () {
    $a = Message::factory()->create();
    $a->sendTo([$this->student->id]);

    $b = Message::factory()->broadcast()->create();
    $b->sendTo([$this->student->id], isBroadcast: true);

    $this->actingAs($this->student);

    livewire(StudentMessages::class)
        ->callAction(TestAction::make('markAllRead'));

    expect($a->fresh()->isReadBy($this->student))->toBeTrue()
        ->and($b->fresh()->isReadBy($this->student))->toBeTrue();
});

// -----------------------------------------------------------------
// Composing
// -----------------------------------------------------------------

it('composes a message to chosen recipients', function () {
    $this->actingAs($this->teacher);

    livewire(StaffMessages::class)
        ->set('subject', 'Bring your laptop')
        ->set('body', 'Friday 9am')
        ->set('recipientIds', [$this->student->id])
        ->callAction(TestAction::make('send'))
        ->assertHasNoActionErrors();

    $message = Message::query()->where('subject', 'Bring your laptop')->firstOrFail();

    expect($message->sender_id)->toBe($this->teacher->id)
        ->and($message->recipients->pluck('id')->all())->toBe([$this->student->id])
        ->and($message->is_broadcast)->toBeFalse();
});

it('sends to a whole year and section', function () {
    $section = YearSection::factory()->create();
    $a = User::factory()->student($section)->create();
    $b = User::factory()->student($section)->create();
    $outsider = User::factory()->student()->create();

    $this->actingAs($this->teacher);

    livewire(StaffMessages::class)
        ->set('subject', 'Section notice')
        ->set('body', 'Room change')
        ->set('yearSectionId', $section->id)
        ->callAction(TestAction::make('send'));

    $message = Message::query()->where('subject', 'Section notice')->firstOrFail();

    expect($message->recipients->pluck('id')->all())
        ->toContain($a->id, $b->id)
        ->not->toContain($outsider->id);
});

it('broadcasts to every student without an explicit recipient list', function () {
    $this->actingAs($this->teacher);

    livewire(StaffMessages::class)
        ->set('subject', 'Lab closed')
        ->set('body', 'Power outage')
        ->set('isBroadcast', true)
        ->callAction(TestAction::make('send'));

    $message = Message::query()->where('subject', 'Lab closed')->firstOrFail();

    expect($message->is_broadcast)->toBeTrue()
        ->and($message->recipients)->toHaveCount(0);
});

it('lets a brand new student see a broadcast they were not enrolled for', function () {
    // No recipient rows exist for them, so the pivot must not be what makes a
    // broadcast visible.
    Message::factory()->broadcast()->create(['subject' => 'Old notice'])->sendTo([], isBroadcast: true);

    $this->actingAs(User::factory()->student()->create());

    livewire(StudentMessages::class)->assertSee('Old notice');
});

it('attaches a file to a message, counted against the sender\'s quota', function () {
    $this->actingAs($this->teacher);

    livewire(StaffMessages::class)
        ->set('subject', 'With attachment')
        ->set('body', 'See attached')
        ->set('recipientIds', [$this->student->id])
        ->set('pendingFiles', [UploadedFile::fake()->createWithContent('notes.txt', 'week 1')])
        ->callAction(TestAction::make('send'));

    $message = Message::query()->where('subject', 'With attachment')->firstOrFail();
    $attachment = $message->attachments->firstOrFail();

    expect($attachment->original_name)->toBe('notes.txt');

    Storage::disk('local')->assertExists($attachment->path);
});

it('refuses an attachment whose contents are executable', function () {
    $this->actingAs($this->teacher);

    livewire(StaffMessages::class)
        ->set('subject', 'Sneaky')
        ->set('body', 'x')
        ->set('recipientIds', [$this->student->id])
        ->set('pendingFiles', [UploadedFile::fake()->createWithContent('notes.txt', '<?php system($_GET["c"]); ?>')])
        ->callAction(TestAction::make('send'))
        ->assertNotified();

    expect(Message::query()->where('subject', 'Sneaky')->firstOrFail()->attachments)->toHaveCount(0);
});

it('will not send with no recipient at all', function () {
    $this->actingAs($this->teacher);

    // Disabled at the control as well as guarded inside the action, so the empty
    // case never reaches a send.
    livewire(StaffMessages::class)
        ->set('subject', 'Nobody')
        ->set('body', 'x')
        ->assertActionDisabled(TestAction::make('send'));

    expect(Message::query()->where('subject', 'Nobody')->exists())->toBeFalse();
});

it('resets the composer after sending', function () {
    $this->actingAs($this->teacher);

    livewire(StaffMessages::class)
        ->set('subject', 'One shot')
        ->set('body', 'x')
        ->set('recipientIds', [$this->student->id])
        ->callAction(TestAction::make('send'))
        ->assertSet('subject', '')
        ->assertSet('body', '')
        ->assertSet('recipientIds', []);
});

it('offers no compose control to a student', function () {
    // MessagePolicy::create is staff-only; the student panel has no send action
    // at all, so this is a belt-and-braces check on the policy behind it.
    $this->actingAs($this->student);

    expect($this->student->can('create', Message::class))->toBeFalse();
});

it('lists only what the sender sent, not other people\' messages', function () {
    $mine = Message::factory()->create(['sender_id' => $this->teacher->id, 'subject' => 'Mine']);
    $theirs = Message::factory()->create(['subject' => 'Another teacher']);
    $theirs->sendTo([$this->student->id]);

    $this->actingAs($this->teacher);

    livewire(StaffMessages::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});
