<?php

use App\Enums\GroupRole;
use App\Filament\Pages\ChatRoom;
use App\Filament\Student\Pages\ChatRoom as StudentChatRoom;
use App\Models\GroupChat;
use App\Models\User;

it('opens a usable New Group form and creates the group', function (): void {
    $teacher = User::factory()->teacher()->create();
    $this->actingAs($teacher);

    $page = livewire(ChatRoom::class);

    // The action must resolve, or pressing the button does nothing at all.
    $page->assertActionVisible('createGroup');

    $before = GroupChat::count();

    // And the handler must create the group once the button is pressed.
    $page->callAction('createGroup', ['name' => 'Year 11 Graphics', 'description' => '']);

    expect(GroupChat::count())->toBe($before + 1);
});

it('gives staff New Group and students Join with code, and never the other way round', function (): void {
    $teacher = User::factory()->teacher()->create();
    $student = User::factory()->student()->create();

    $group = GroupChat::factory()->create(['name' => 'Year 10 Web Dev', 'created_by_id' => $teacher->id]);
    $group->join($teacher, GroupRole::Admin);
    $group->join($student);

    // A button only works if Filament can resolve the action its click names, and registering
    // in getHeaderActions() is what puts it in that cache.
    $this->actingAs($teacher);
    $staff = livewire(ChatRoom::class)
        ->set('groupId', $group->id)
        ->assertActionVisible('createGroup')
        ->assertActionVisible('renameGroup');

    $this->actingAs($student);
    $pupil = livewire(StudentChatRoom::class)
        ->set('groupId', $group->id)
        ->assertActionVisible('joinByCode');

    // Visibility is checked on what each person is actually shown, because "registered" and
    // "offered" are separate decisions: the staff page has no joinByCode action at all, and
    // the student page has no createGroup one.
    expect($staff->html())
        ->toContain('mountAction(\'createGroup\')')
        ->not->toContain('mountAction(\'joinByCode\')');

    expect($pupil->html())
        ->toContain('mountAction(\'joinByCode\')')
        ->not->toContain('mountAction(\'createGroup\')');
});

it('draws every action exactly once', function (): void {
    $teacher = User::factory()->teacher()->create();
    $student = User::factory()->student()->create();

    $group = GroupChat::factory()->create(['name' => 'Year 10 Web Dev', 'created_by_id' => $teacher->id]);
    $group->join($teacher, GroupRole::Admin);
    $group->join($student);

    // Filament's page header renders every action returned by getHeaderActions(), and caches
    // it for lookup. The view used to draw those same actions too, so each appeared twice -
    // two buttons per function. Counted as real <button> elements rather than substrings,
    // because a name also appears in a click handler, in Alpine state and in the Livewire
    // snapshot, and matching those would pass while the UI stayed doubled.
    $this->actingAs($teacher);
    $staffHtml = livewire(ChatRoom::class)->set('groupId', $group->id)->html();

    // The dropdown's trigger renders its members' names into the page so the browser knows
    // what is behind it, but only one clickable element per function - counted as real
    // <button> elements rather than substrings, since a name also appears in a click
    // handler, in Alpine state and in the Livewire snapshot.
    preg_match_all('/<button[^>]*mountAction\(\'createGroup\'\)/', $staffHtml, $matches);
    expect($matches[0])->toHaveCount(1, 'New Group is drawn more than once');

    expect(substr_count($staffHtml, 'fi-dropdown-trigger'))
        ->toBe(1, 'expected exactly one group options dropdown');

    // renameGroup and leaveGroup are rendered inside the dropdown panel and hidden with
    // x-cloak until it is opened, so each still appears once - but as a list item, never as
    // a button of its own on the group header. Asserted on the class, so a later change that
    // moves them back out of the panel fails here rather than looking identical in HTML.
    foreach (['renameGroup'] as $action) {
        expect(str_contains($staffHtml, 'fi-dropdown-list-item'))
            ->toBeTrue();

        expect(preg_match(
            '/<button[^>]*mountAction\(\''.$action.'\'\)[^>]*class="[^"]*fi-dropdown-list-item/s',
            $staffHtml
        ))->toBe(1, $action.' should be an item inside the group options dropdown');
    }

    // Leave is absent for the group's owner, and that is the policy rather than a broken
    // link: GroupChatPolicy::leave refuses when created_by_id is the caller, because an
    // owner who left would strand the group they just made. So the owner is checked for its
    // absence here, and the member - rendered on the student panel - for its presence.
    expect(str_contains($staffHtml, "mountAction('leaveGroup')"))
        ->toBeFalse('the owner must not be offered Leave group');

    $this->actingAs($student);
    $pupilHtml = livewire(StudentChatRoom::class)->set('groupId', $group->id)->html();

    // The member does get Leave, inside the same dropdown.
    expect(preg_match(
        '/<button[^>]*mountAction\(\'leaveGroup\'\)[^>]*class="[^"]*fi-dropdown-list-item/s',
        $pupilHtml
    ))->toBe(1, 'a member should be offered Leave group inside the dropdown');

    preg_match_all('/<button[^>]*mountAction\(\'joinByCode\'\)/', $pupilHtml, $matches);
    expect($matches[0])->toHaveCount(1, 'Join with code is drawn more than once');

    expect(substr_count($pupilHtml, 'fi-dropdown-trigger'))
        ->toBe(1, 'the student panel should have the same single group options dropdown');
});

it('puts group controls on the group and page controls in the page header', function (): void {
    $teacher = User::factory()->teacher()->create();

    $group = GroupChat::factory()->create(['name' => 'Year 10 Web Dev', 'created_by_id' => $teacher->id]);
    $group->join($teacher, GroupRole::Admin);

    $this->actingAs($teacher);
    $html = livewire(ChatRoom::class)->set('groupId', $group->id)->html();

    // Split at the room list. The page header comes first, then the sidebar of groups, then the
    // panel for whichever group is open - so the group controls, which sit in that panel's
    // header, are all after the split.
    $splitAt = strpos($html, 'selectGroup(');

    expect($splitAt)->not->toBeFalse();

    $page = substr($html, 0, $splitAt);
    $group = substr($html, $splitAt);

    // New Group creates something and so applies to the page, not to any one group. The
    // group options dropdown acts on the group that is open, so it belongs beside its name
    // - in the page header they read as if they applied to every group in the list.
    expect($page)->toContain('mountAction(\'createGroup\')')
        ->and($page)->not->toContain('fi-dropdown-trigger');

    // Pest's toContain is variadic - every argument is treated as a needle - so a failure
    // message cannot be passed as one. Asserted separately so the failing line names the
    // thing instead of printing the whole markup.
    expect(str_contains($group, 'fi-dropdown-trigger'))
        ->toBeTrue('the group options dropdown should be drawn on the open group');
});

it('still resolves group actions that are not registered as header actions', function (): void {
    $teacher = User::factory()->teacher()->create();
    $this->actingAs($teacher);

    $group = GroupChat::factory()->create(['name' => 'Year 10 Web Dev', 'created_by_id' => $teacher->id]);
    $group->join($teacher, GroupRole::Admin);

    // resolveAction falls back to a naming convention: `renameGroup` resolves to this
    // component's renameGroupAction() method even though it is not in the action cache. That
    // fallback is the only reason the group controls can live in the Blade without being
    // drawn again by the page header, so it is worth asserting rather than assuming.
    livewire(ChatRoom::class)
        ->set('groupId', $group->id)
        ->assertActionExists('renameGroup')
        ->assertActionExists('leaveGroup')
        ->assertActionExists('createGroup');
});

it('offers a copy button beside the join code', function (): void {
    $teacher = User::factory()->teacher()->create();
    $this->actingAs($teacher);

    $group = GroupChat::factory()->create(['name' => 'Year 10 Web Dev', 'created_by_id' => $teacher->id]);
    $group->join($teacher, GroupRole::Admin);

    $html = livewire(ChatRoom::class)->set('groupId', $group->id)->html();

    // Reading eight characters off a screen and onto a whiteboard is the friction this
    // removes, so the code and its copy control have to travel together.
    expect($html)
        ->toContain($group->join_code)
        ->toContain('navigator.clipboard.writeText')
        ->toContain('Copy join code');
});
