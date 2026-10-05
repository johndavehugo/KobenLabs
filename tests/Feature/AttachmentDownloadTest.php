<?php

use App\Models\GroupChat;
use App\Models\GroupChatMessage;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Services\FileService;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Attachment downloads.
 *
 * Two legacy defects are pinned here:
 *
 *   1. The API listed attachments on a message but served none, so the feature
 *      was unusable.
 *   2. `download-group-file.php` took a path and performed no membership check,
 *      so any signed-in user could fetch any group's attachments.
 *
 * The fix in both cases is to resolve a model and ask a policy, never to accept a
 * path and never to trust an id.
 */
beforeEach(function () {
    Storage::fake('local');

    $this->service = app(FileService::class);
    $this->teacher = User::factory()->teacher()->create();
    $this->student = User::factory()->student()->create();
});

function attachmentUrl(MessageAttachment $attachment): string
{
    return route('messages.attachments.download', ['attachment' => $attachment->id]);
}

function groupFileUrl(GroupChatMessage $message): string
{
    return route('group-chat.files.download', ['message' => $message->id]);
}

function realMessageAttachment(User $sender, User $recipient): MessageAttachment
{
    $message = Message::factory()->create(['sender_id' => $sender->id]);
    $message->sendTo([$recipient->id]);

    return app(FileService::class)->storeMessageAttachment(
        UploadedFile::fake()->createWithContent('notes.txt', 'hello'),
        $sender,
        $message->id,
    );
}

// -----------------------------------------------------------------
// Message attachments
// -----------------------------------------------------------------

it('lets a recipient download an attachment on their message', function () {
    $attachment = realMessageAttachment($this->teacher, $this->student);

    $this->actingAs($this->student)
        ->get(attachmentUrl($attachment))
        ->assertSuccessful()
        ->assertDownload($attachment->original_name);
});

it('lets the sender download an attachment they sent', function () {
    $attachment = realMessageAttachment($this->teacher, $this->student);

    $this->actingAs($this->teacher)
        ->get(attachmentUrl($attachment))
        ->assertSuccessful();
});

it('refuses the download to somebody who is neither sender nor recipient', function () {
    $attachment = realMessageAttachment($this->teacher, $this->student);
    $outsider = User::factory()->student()->create();

    $this->actingAs($outsider)
        ->get(attachmentUrl($attachment))
        ->assertForbidden();
});

it('refuses the download to an administrator who is not a recipient', function () {
    $attachment = realMessageAttachment($this->teacher, $this->student);

    // MessagePolicy::view is sender-or-recipient with no staff override, on
    // purpose: an admin reading a teacher's private mail is not support work.
    $this->actingAs(User::factory()->admin()->create())
        ->get(attachmentUrl($attachment))
        ->assertForbidden();
});

it('refuses the download to a guest', function () {
    $attachment = realMessageAttachment($this->teacher, $this->student);

    $this->get(attachmentUrl($attachment))
        ->assertRedirect(Filament::getPanel('admin')->getLoginUrl());
});

it('reports a missing blob as not found rather than an empty file', function () {
    $attachment = realMessageAttachment($this->teacher, $this->student);

    // A failed legacy delete leaves exactly this: a row with no file behind it.
    Storage::disk('local')->delete($attachment->path);

    $this->actingAs($this->student)
        ->get(attachmentUrl($attachment))
        ->assertNotFound();
});

it('does not let a crafted filename inject response headers', function () {
    $message = Message::factory()->create(['sender_id' => $this->teacher->id]);
    $message->sendTo([$this->student->id]);

    $attachment = app(FileService::class)->storeMessageAttachment(
        UploadedFile::fake()->createWithContent("evil\r\nX-Injected: yes.txt", 'x'),
        $this->teacher,
        $message->id,
    );

    $response = $this->actingAs($this->student)->get(attachmentUrl($attachment));

    $response->assertSuccessful();

    expect($response->headers->get('x-injected'))->toBeNull();
});

it('sends a guest to the panel login rather than erroring', function () {
    $attachment = realMessageAttachment($this->teacher, $this->student);

    // Filament registers no bare `login` route, so the auth middleware's default
    // redirect used to throw and return a 500 for every guest.
    $this->get(attachmentUrl($attachment))
        ->assertRedirect(Filament::getPanel('admin')->getLoginUrl())
        ->assertSessionHasNoErrors();
});

// -----------------------------------------------------------------
// Group chat files
// -----------------------------------------------------------------

it('lets a member download a file shared in their group', function () {
    $group = GroupChat::factory()->create();
    $group->join($this->student);

    $this->actingAs($this->student);

    $file = $this->service->storeGroupChatFile(
        UploadedFile::fake()->createWithContent('notes.txt', 'week 1'),
        $this->student,
        $group,
    );

    $this->actingAs($this->student)
        ->get(groupFileUrl($file))
        ->assertSuccessful()
        ->assertDownload('notes.txt');
});

it('refuses the group file download to a non-member', function () {
    $group = GroupChat::factory()->create();
    $group->join($this->student);

    $this->actingAs($this->student);

    $file = $this->service->storeGroupChatFile(
        UploadedFile::fake()->createWithContent('notes.txt', 'week 1'),
        $this->student,
        $group,
    );

    $this->actingAs(User::factory()->student()->create())
        ->get(groupFileUrl($file))
        ->assertForbidden();
});

it('lets lab staff download a group file without being a member', function () {
    $group = GroupChat::factory()->create();
    $group->join($this->student);

    $this->actingAs($this->student);

    $file = $this->service->storeGroupChatFile(
        UploadedFile::fake()->createWithContent('notes.txt', 'week 1'),
        $this->student,
        $group,
    );

    $this->actingAs($this->teacher)
        ->get(groupFileUrl($file))
        ->assertSuccessful();
});

it('refuses the group file download to a guest', function () {
    $group = GroupChat::factory()->create();
    $group->join($this->student);

    // Written directly rather than through FileService, because the service
    // authorizes on the authenticated user and this test is about having none.
    $path = 'group-chats/'.$group->id.'/notes.txt';
    Storage::disk('local')->put($path, 'week 1');

    $file = GroupChatMessage::factory()->file()->create([
        'group_chat_id' => $group->id,
        'sender_id' => $this->student->id,
        'file_path' => $path,
        'file_name' => 'notes.txt',
    ]);

    $this->get(groupFileUrl($file))
        ->assertRedirect(Filament::getPanel('admin')->getLoginUrl());
});

it('returns not found for a text-only message asked for as a file', function () {
    $group = GroupChat::factory()->create();
    $group->join($this->student);

    $message = GroupChatMessage::factory()->create([
        'group_chat_id' => $group->id,
        'sender_id' => $this->student->id,
    ]);

    $this->actingAs($this->student)
        ->get(groupFileUrl($message))
        ->assertNotFound();
});

it('reports a missing group blob as not found', function () {
    $group = GroupChat::factory()->create();
    $group->join($this->student);

    $this->actingAs($this->student);

    $file = $this->service->storeGroupChatFile(
        UploadedFile::fake()->createWithContent('notes.txt', 'week 1'),
        $this->student,
        $group,
    );

    Storage::disk('local')->delete($file->file_path);

    $this->actingAs($this->student)
        ->get(groupFileUrl($file))
        ->assertNotFound();
});
