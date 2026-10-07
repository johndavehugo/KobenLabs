<?php

use App\Enums\PcStatus;
use App\Filament\Student\Pages\ChatRoom as StudentChatRoom;
use App\Filament\Student\Pages\MyDrive;
use App\Filament\Student\Widgets\StudentWelcome;
use App\Filament\Widgets\LabOverview;
use App\Models\GroupChat;
use App\Models\Pc;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Accessibility checks for the surfaces this app wrote itself.
 *
 * Filament's own components ship accessible defaults, so the risk here is in the
 * markup added on top: custom widgets, custom columns and custom labels. Each test
 * below pins a specific defect that was either present and fixed, or was a
 * plausible way for the next change to reintroduce it.
 *
 * This is a focused review, not a WCAG conformance claim.
 */
beforeEach(function () {
    $this->student = User::factory()->student()->create();
    $this->actingAs($this->student);
});

it('names every column that carries meaning through an icon', function () {
    $file = StoredFile::factory()->create(['user_id' => $this->student->id]);

    $html = livewire(MyDrive::class, ['record' => null])->html();

    // An IconColumn with ->label('') renders a <th> with no accessible name, so
    // the only thing telling a screen reader that this row is a folder is the
    // glyph.
    expect($html)->toContain('Kind')
        ->and($html)->not->toContain('<th scope="col" class="fi-ta-header-cell is-folder" >');
});

it('describes the drive preview image rather than leaving it unnamed', function () {
    // The column only resolves a state when the blob actually exists, so the test
    // puts one there; otherwise it correctly renders nothing at all.
    Storage::fake('local');

    $file = StoredFile::factory()->image()->create([
        'user_id' => $this->student->id,
        'name' => 'diagram.png',
    ]);

    Storage::disk('local')->put($file->path, 'fake png bytes');

    $html = livewire(MyDrive::class)->set('viewMode', 'grid')->html();

    expect($html)->toContain('Preview of diagram.png');
});

it('gives the chat transcript real landmarks and headings', function () {
    $group = GroupChat::factory()->withMembers($this->student)->create();

    $html = livewire(StudentChatRoom::class)
        ->set('groupId', $group->id)
        ->html();

    // The transcript is a live region, so a message arriving is announced without
    // moving focus out of the composer. The roster is a complementary landmark beside
    // it, and both carry headings so a keyboard or screen reader user can jump
    // between the parts of the page.
    expect($html)->toContain('role="log"')
        ->toContain('aria-live="polite"')
        ->toContain('Members')
        ->toContain('<aside');
});

it('marks decorative icons as hidden from assistive technology', function () {
    $html = livewire(StudentWelcome::class)->html();

    // The quick-action icons sit next to real link text, so exposing them again
    // just makes the link read twice.
    expect($html)->toContain('aria-hidden="true"');
});

it('gives the recent activity times a machine-readable value', function () {
    StoredFile::factory()->create([
        'user_id' => $this->student->id,
        'name' => 'revision.txt',
    ]);

    $html = livewire(StudentWelcome::class)->html();

    // "2 hours ago" is unreadable to a screen reader user; the ISO value in the
    // datetime attribute is not.
    expect($html)->toMatch('/<time\s+datetime="[^"]+"/');
});

it('does not rely on colour alone for the lab overview figures', function () {
    Pc::factory()->count(2)->create(['status' => PcStatus::Online]);
    Pc::factory()->create(['status' => PcStatus::Maintenance]);

    $html = livewire(LabOverview::class)->html();

    // Every figure is a text label with a number, not a coloured dot.
    expect($html)->toContain('PCs online')
        ->toContain('2 / 3')
        ->toContain('In maintenance');
});

it('keeps the welcome widget readable with no JavaScript polling', function () {
    // The dashboard's initial HTML holds only a Livewire reference per widget, so
    // this asserts the widget body itself is complete server-side rather than
    // depending on a follow-up request.
    $html = livewire(StudentWelcome::class)->html();

    expect($html)->toContain('Quick actions')
        ->toContain('Recent activity')
        ->toContain('My Drive');
});
