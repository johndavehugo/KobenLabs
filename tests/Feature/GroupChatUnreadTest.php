<?php

use App\Enums\GroupMessageType;
use App\Enums\GroupRole;
use App\Filament\Pages\ChatRoom;
use App\Models\GroupChat;
use App\Models\GroupChatMessage;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->teacher = User::factory()->teacher()->create();
    $this->student = User::factory()->student()->create();

    $this->group = GroupChat::factory()->create([
        'name' => 'Year 10 History',
        'created_by_id' => $this->teacher->id,
    ]);

    $this->group->join($this->teacher, GroupRole::Admin);
    $this->group->join($this->student);
});

function postMessage(GroupChat $group, ?User $sender, string $body = 'Hello'): GroupChatMessage
{
    return $group->chatMessages()->create([
        'sender_id' => $sender?->id,
        'body' => $body,
        'type' => GroupMessageType::Text,
    ]);
}

it('counts another members messages as unread', function (): void {
    postMessage($this->group, $this->teacher);
    postMessage($this->group, $this->teacher);

    $this->actingAs($this->student);
    livewire(ChatRoom::class)->assertSee('title="2 unread"', escape: false);
});

it('does not count your own messages as unread', function (): void {
    // Two messages, one of them the student's. Somebody has by definition read what they
    // wrote, so counting it would leave a permanent unread badge on every group a user has
    // ever posted in - and would mean a badge that never clears on a busy group.
    postMessage($this->group, $this->student);
    postMessage($this->group, $this->teacher);

    $this->actingAs($this->student);
    livewire(ChatRoom::class)->assertSee('title="1 unread"', escape: false);
});

it('counts a system message as unread because it has no author', function (): void {
    $this->group->systemMessage('Ana joined the group.');

    $this->actingAs($this->student);
    livewire(ChatRoom::class)->assertSee('title="1 unread"', escape: false);
});

it('clears the badge when the group is opened', function (): void {
    postMessage($this->group, $this->teacher);

    $this->actingAs($this->student);
    livewire(ChatRoom::class)->assertSee('title="1 unread"', escape: false);

    livewire(ChatRoom::class)->call('selectGroup', $this->group->id)
        ->assertDontSee('unread messages', escape: false);
});

it('clears the badge when a group is opened straight from a link', function (): void {
    postMessage($this->group, $this->teacher);

    // groupId is a Url property, so ?group=N is a page load of that conversation and never
    // reaches selectGroup(). Without the mount path the badge survived a refresh of the
    // group you are looking at.
    $this->actingAs($this->student);
    Livewire::withQueryParams(['group' => $this->group->id])
        ->test(ChatRoom::class)
        ->assertSet('groupId', $this->group->id)
        ->assertDontSee('unread messages', escape: false);
});

it('leaves other groups unread', function (): void {
    $other = GroupChat::factory()->create([
        'name' => 'Year 11 Biology',
        'created_by_id' => $this->teacher->id,
    ]);
    $other->join($this->student);
    postMessage($other, $this->teacher);

    postMessage($this->group, $this->teacher);

    $this->actingAs($this->student);
    livewire(ChatRoom::class)
        ->call('selectGroup', $this->group->id)
        ->assertSee('title="1 unread"', escape: false);
});

it('does not mark anything read twice when two tabs agree', function (): void {
    postMessage($this->group, $this->teacher);

    $this->actingAs($this->student);

    // The unique key on (message_id, user_id) is why the insert ignores rather than
    // upserts: the second tab would otherwise fail the request outright.
    livewire(ChatRoom::class)->call('selectGroup', $this->group->id);
    livewire(ChatRoom::class)->call('selectGroup', $this->group->id);

    $rows = DB::table('group_message_reads')
        ->where('user_id', $this->student->id)
        ->count();

    expect($rows)->toBe(1);
});

it('reports how many messages it marked rather than rewriting old receipts', function (): void {
    $first = postMessage($this->group, $this->teacher, 'First');
    postMessage($this->group, $this->teacher, 'Second');

    expect($this->group->markAllReadBy($this->student))->toBe(2);

    // Opening the same group again marks nothing, and must not reset the time the
    // messages were actually read.
    expect($this->group->markAllReadBy($this->student))->toBe(0);

    $readAt = DB::table('group_message_reads')
        ->where('message_id', $first->id)
        ->value('read_at');

    expect($readAt)->not->toBeNull();
});

it('gives each member their own unread count', function (): void {
    postMessage($this->group, $this->teacher);

    $this->actingAs($this->teacher);
    livewire(ChatRoom::class)->assertDontSee('unread messages', escape: false);

    $this->actingAs($this->student);
    livewire(ChatRoom::class)->assertSee('title="1 unread"', escape: false);
});
