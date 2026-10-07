<?php

use App\Enums\GroupRole;
use App\Events\GroupChatTyping;
use App\Filament\Pages\ChatRoom;
use App\Models\GroupChat;
use App\Models\User;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    Event::fake([GroupChatTyping::class]);

    $this->teacher = User::factory()->teacher()->create();
    $this->actingAs($this->teacher);

    $this->group = GroupChat::factory()->create([
        'name' => 'Year 10 Web Dev',
        'created_by_id' => $this->teacher->id,
    ]);
    $this->group->join($this->teacher, GroupRole::Admin);
});

it('broadcasts typing when the composer changes', function (): void {
    // Typing raises a BadMethodCallException on every keystroke: the code called a throttle()
    // method that exists on neither the Livewire component nor its trait, so the whole page
    // died on the first character typed. The composer is watched in JS, which dispatches this
    // event rather than a draft update - so that is what the test drives.
    livewire(ChatRoom::class)
        ->set('groupId', $this->group->id)
        ->set('draft', 'Half')
        ->dispatch('koben:chat-typing');

    Event::assertDispatched(GroupChatTyping::class);
});

it('rate limits typing broadcasts rather than firing one per keystroke', function (): void {
    $page = livewire(ChatRoom::class)->set('groupId', $this->group->id);

    // A burst of keystrokes should not be a burst of broadcasts: every message has to be
    // received and discarded by every other member of the room.
    foreach (['H', 'Ha', 'Hal', 'Half'] as $keystroke) {
        $page->set('draft', $keystroke)->dispatch('koben:chat-typing');
    }

    Event::assertDispatchedTimes(GroupChatTyping::class, 1);
});

it('sends nothing for an empty draft', function (): void {
    // Clearing the composer is not typing, and the previous draft must not leave an
    // indicator behind.
    livewire(ChatRoom::class)
        ->set('groupId', $this->group->id)
        ->set('draft', '');

    Event::assertNotDispatched(GroupChatTyping::class);
});
