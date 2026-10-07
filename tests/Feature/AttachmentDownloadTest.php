<?php

use App\Models\GroupChat;
use App\Models\GroupChatMessage;
use App\Models\User;
use App\Services\FileService;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Group chat attachment downloads.
 *
 * A legacy defect is pinned here: `download-group-file.php` took a path and performed no
 * membership check, so any signed-in user could fetch any group's attachments.
 *
 * The fix is to resolve a model and ask a policy, never to accept a path and never to
 * trust an id.
 *
 * This file used to cover direct-message attachments as well. Direct messaging is gone —
 * group chat replaced it — so the second route and the whole first half of these tests
 * went with it. The header-injection test survives below, applied to the group route,
 * because that is the route that still exists.
 */
beforeEach(function () {
    Storage::fake('local');

    $this->service = app(FileService::class);
    $this->teacher = User::factory()->teacher()->create();
    $this->student = User::factory()->student()->create();
});

function groupFileUrl(GroupChatMessage $message): string
{
    return route('group-chat.files.download', ['message' => $message->id]);
}

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

it('does not let a crafted filename inject response headers', function () {
    $group = GroupChat::factory()->create();
    $group->join($this->student);

    $this->actingAs($this->student);

    $file = $this->service->storeGroupChatFile(
        UploadedFile::fake()->createWithContent("evil\r\nX-Injected: yes.txt", 'x'),
        $this->student,
        $group,
    );

    $response = $this->actingAs($this->student)->get(groupFileUrl($file));

    $response->assertSuccessful();

    expect($response->headers->get('x-injected'))->toBeNull();
});
