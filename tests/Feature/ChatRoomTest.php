<?php

use App\Enums\GroupMessageType;
use App\Enums\GroupRole;
use App\Filament\Pages\ChatRoom as AdminChatRoom;
use App\Filament\Student\Pages\ChatRoom;
use App\Models\GroupChat;
use App\Models\GroupChatMessage;
use App\Models\User;
use App\Services\FileService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

beforeEach(function () {
    Storage::fake('local');

    $this->service = app(FileService::class);
    $this->student = User::factory()->student()->create();
});

// -----------------------------------------------------------------
// Panel gating
// -----------------------------------------------------------------

it('serves the transcript to a member', function () {
    $group = GroupChat::factory()->withMembers($this->student)->create();

    $this->actingAs($this->student)
        ->get(ChatRoom::getUrl(panel: 'student'))
        ->assertSuccessful();
})->group('gating');

it('keeps a non-member off a group they hold no code for', function () {
    $group = GroupChat::factory()->create();

    $this->actingAs($this->student);

    livewire(ChatRoom::class)
        ->set('groupId', $group->id)
        ->assertSet('groupId', null)
        ->assertDontSee($group->name);
});

it('lists only your own groups as a student', function () {
    $mine = GroupChat::factory()->withMembers($this->student)->create();
    $theirs = GroupChat::factory()->create();

    $this->actingAs($this->student);

    expect(livewire(ChatRoom::class)->instance()->availableGroups()->pluck('id')->all())
        ->toBe([$mine->id])
        ->not->toContain($theirs->id);
});

it('lists only the groups a staff member created', function () {
    $mine = GroupChat::factory()->create();
    $theirs = GroupChat::factory()->create();

    $teacher = User::factory()->teacher()->create();
    $mine->forceFill(['created_by_id' => $teacher->id])->save();
    $theirs->forceFill(['created_by_id' => User::factory()->admin()->create()->id])->save();

    $this->actingAs($teacher);

    // Staff used to see every active group in the lab. A teacher's group is a room for
    // one class, so the sidebar listing other teachers' rooms put conversations in front
    // of people with no part in them.
    expect(livewire(AdminChatRoom::class)->instance()->availableGroups()->pluck('id')->all())
        ->toBe([$mine->id])
        ->not->toContain($theirs->id);
});

// -----------------------------------------------------------------
// Sending
// -----------------------------------------------------------------

it('posts a message to the group', function () {
    $group = GroupChat::factory()->withMembers($this->student)->create();

    $this->actingAs($this->student);

    livewire(ChatRoom::class)
        ->set('groupId', $group->id)
        ->set('draft', 'See you at 1pm')
        ->callAction(TestAction::make('sendMessage'))
        ->assertSet('draft', '')
        ->assertHasNoActionErrors();

    expect($group->chatMessages()->where('body', 'See you at 1pm')->exists())->toBeTrue()
        ->and($group->chatMessages()->first()->sender_id)->toBe($this->student->id);
});

/**
 * Put a file into Livewire's temporary upload store and wrap it as the composer would
 * receive it.
 *
 * These two tests used to hand a raw `UploadedFile` to ->set('pendingFiles', ...), which
 * worked while the composer was a Filament FileUpload component and stops working now that
 * it is a plain file input. The reason is the snapshot, not the property: Livewire has to
 * JSON-encode its state between requests, and an UploadedFile inside an array cannot be
 * encoded - hence "Invalid Livewire snapshot structure".
 *
 * Going through the real temporary-upload store keeps the test on the same path the
 * browser takes, rather than reaching around Livewire to inject an object the page would
 * never really hold.
 */
function composerUpload(string $name, string $contents): TemporaryUploadedFile
{
    // Livewire moves temporary uploads to a disk called `tmp-for-tests` while a component
    // is under test, and nothing in this app declares it - so the helper declares one
    // rather than writing somewhere the component will not look.
    config()->set('filesystems.disks.tmp-for-tests', [
        'driver' => 'local',
        'root' => storage_path('framework/testing/disks/tmp-for-tests'),
    ]);

    // Through the configured disk rather than FileUploadConfiguration::path(): inside a
    // Livewire test the temporary uploads move to a disk that is not the app's default,
    // so writing to the app's default would put the file where the component is not
    // looking.
    $disk = Storage::disk(FileUploadConfiguration::disk());

    $disk->put($name, $contents);

    return TemporaryUploadedFile::createFromLivewire($name);
}

it('shares a file through the group, subject to the group allowlist', function () {
    $group = GroupChat::factory()->withMembers($this->student)->create();

    $this->actingAs($this->student);

    livewire(ChatRoom::class)
        ->set('groupId', $group->id)
        ->set('pendingFiles', [composerUpload('notes.txt', 'week 1 reading')])
        ->call('sendMessage');

    $message = $group->chatMessages()->where('type', GroupMessageType::File)->firstOrFail();

    expect($message->file_name)->toBe('notes.txt')
        ->and(Storage::disk('local')->exists($message->file_path))->toBeTrue();
});

it('refuses a group file whose contents are executable', function () {
    $group = GroupChat::factory()->withMembers($this->student)->create();

    $this->actingAs($this->student);

    livewire(ChatRoom::class)
        ->set('groupId', $group->id)
        ->set('pendingFiles', [composerUpload('payload.txt', '<?php system($_GET["c"]); ?>')])
        ->call('sendMessage')
        ->assertNotified();

    expect($group->chatMessages()->count())->toBe(0);
});

it('cannot post to a group you are not in', function () {
    $group = GroupChat::factory()->create();

    $this->actingAs($this->student);

    // A tampered group id is dropped by resolution, so the send control is
    // hidden. Header actions stay registered when hidden (unlike actions inside a
    // schema), so the assertion is that it is hidden, not absent.
    livewire(ChatRoom::class)
        ->set('groupId', $group->id)
        ->assertSet('groupId', null)
        ->assertActionHidden(TestAction::make('sendMessage'));

    expect($group->chatMessages()->count())->toBe(0);
});

// -----------------------------------------------------------------
// Joining by code
// -----------------------------------------------------------------

it('joins an active group with the right code', function () {
    $group = GroupChat::factory()->create();

    $this->actingAs($this->student);

    livewire(ChatRoom::class)
        ->callAction(TestAction::make('joinByCode'), ['join_code' => $group->join_code])
        ->assertSet('groupId', $group->id);

    expect($group->isMember($this->student))->toBeTrue();
});

it('says in the room who joined it', function () {
    $group = GroupChat::factory()->create();

    $this->actingAs($this->student);

    livewire(ChatRoom::class)
        ->callAction(TestAction::make('joinByCode'), ['join_code' => $group->join_code]);

    // Someone handing a code round a classroom has no other way to know who took it up.
    expect($group->chatMessages()->where('type', GroupMessageType::System)->count())->toBe(1)
        ->and($group->chatMessages()->latest('id')->first()->body)
        ->toContain($this->student->full_name);
});

it('says in the room who left it, while they are still a member', function () {
    $group = GroupChat::factory()->withMembers($this->student)->create();

    $this->actingAs($this->student);

    livewire(ChatRoom::class)
        ->set('groupId', $group->id)
        ->callAction(TestAction::make('leaveGroup'))
        ->assertSet('groupId', null);

    // The announcement has to be written before the membership row goes, or
    // GroupChatMessagePolicy - which defers to the group policy - would refuse a leaver's
    // own goodbye and the room would show nothing at all.
    expect($group->chatMessages()->where('type', GroupMessageType::System)->count())->toBe(1)
        ->and($group->chatMessages()->latest('id')->first()->body)
        ->toContain('left the group');
});

it('refuses a wrong code without saying whether the group exists', function () {
    GroupChat::factory()->create();

    $this->actingAs($this->student);

    livewire(ChatRoom::class)
        ->callAction(TestAction::make('joinByCode'), ['join_code' => 'NOPENOPE'])
        ->assertSet('groupId', null)
        ->assertNotified();

    expect($group = GroupChat::query()->first())->not->toBeNull()
        ->and($group->isMember($this->student))->toBeFalse();
});

it('refuses to join an inactive group even with the right code', function () {
    $group = GroupChat::factory()->inactive()->create();

    $this->actingAs($this->student);

    livewire(ChatRoom::class)
        ->callAction(TestAction::make('joinByCode'), ['join_code' => $group->join_code])
        ->assertSet('groupId', null);

    expect($group->isMember($this->student))->toBeFalse();
});

// -----------------------------------------------------------------
// Leaving and moderation
// -----------------------------------------------------------------

it('leaves a group it joined', function () {
    $group = GroupChat::factory()->withMembers($this->student)->create();

    $this->actingAs($this->student);

    livewire(ChatRoom::class)
        ->set('groupId', $group->id)
        ->callAction(TestAction::make('leaveGroup'))
        ->assertSet('groupId', null);

    expect($group->isMember($this->student))->toBeFalse();
});

it('will not let the creator abandon their own group', function () {
    $creator = User::factory()->teacher()->create();
    $group = GroupChat::factory()->create(['created_by_id' => $creator->id]);
    $group->join($creator);

    $this->actingAs($creator);

    // GroupChatPolicy::leave refuses the creator, so the control is not offered.
    expect($creator->can('leave', $group))->toBeFalse();
});

it('lets a member delete their own message but not somebody else\'s', function () {
    // Deliberately not withMembers(): that helper enrols the user as a group
    // ADMIN, which would let them delete anything and hide the sender check.
    $group = GroupChat::factory()->create();
    $group->join($this->student);

    $mine = GroupChatMessage::factory()->create([
        'group_chat_id' => $group->id,
        'sender_id' => $this->student->id,
    ]);
    $theirs = GroupChatMessage::factory()->create([
        'group_chat_id' => $group->id,
        'sender_id' => User::factory()->student()->create()->id,
    ]);

    $this->actingAs($this->student);

    expect($this->student->can('delete', $mine))->toBeTrue()
        ->and($this->student->can('delete', $theirs))->toBeFalse();
});

it('lets a group admin delete a member\'s message', function () {
    $group = GroupChat::factory()->create();
    $group->join($this->student, GroupRole::Admin);

    $theirs = GroupChatMessage::factory()->create([
        'group_chat_id' => $group->id,
        'sender_id' => User::factory()->student()->create()->id,
    ]);

    $this->actingAs($this->student);

    expect($this->student->can('delete', $theirs))->toBeTrue();
});

it('never lets a member of another group delete this one\'s messages', function () {
    $group = GroupChat::factory()->create();
    $outsider = GroupChat::factory()->withMembers($this->student)->create();

    $message = GroupChatMessage::factory()->create([
        'group_chat_id' => $group->id,
        'sender_id' => User::factory()->student()->create()->id,
    ]);

    $this->actingAs($this->student);

    // Being an admin of some other group grants nothing here.
    expect($outsider->roleFor($this->student))->not->toBeNull()
        ->and($this->student->can('delete', $message))->toBeFalse();
});

it('shows only the group you are reading', function () {
    $mine = GroupChat::factory()->withMembers($this->student)->create();
    $other = GroupChat::factory()->withMembers($this->student)->create();

    GroupChatMessage::factory()->create([
        'group_chat_id' => $mine->id,
        'body' => 'mine only',
    ]);
    GroupChatMessage::factory()->create([
        'group_chat_id' => $other->id,
        'body' => 'other only',
    ]);

    $this->actingAs($this->student);

    livewire(ChatRoom::class)
        ->set('groupId', $mine->id)
        ->assertSee('mine only')
        ->assertDontSee('other only');
});
