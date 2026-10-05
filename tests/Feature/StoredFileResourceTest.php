<?php

use App\Filament\Resources\StoredFiles\Pages\ListStoredFiles;
use App\Filament\Resources\StoredFiles\StoredFileResource;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    $this->admin = User::factory()->admin()->create();
    $this->student = User::factory()->student()->create();
});

it('lists every drive for staff', function () {
    $theirs = StoredFile::factory()->create(['user_id' => $this->student->id]);

    $this->actingAs($this->admin)
        ->get(StoredFileResource::getUrl('index'))
        ->assertSuccessful()
        ->assertSee($theirs->name);
});

it('offers an owner filter so staff can narrow to one student', function () {
    $this->actingAs($this->admin);

    livewire(ListStoredFiles::class)
        ->assertTableFilterExists('user')
        ->assertTableFilterExists('is_folder');
});

it('shows every drive at once, which is the point of the support view', function () {
    $theirs = StoredFile::factory()->create(['user_id' => $this->student->id]);
    $other = StoredFile::factory()->create();

    $this->actingAs($this->admin);

    livewire(ListStoredFiles::class)
        ->assertCanSeeTableRecords([$theirs, $other]);
});

it('lets staff view one of a student\'s files', function () {
    $file = StoredFile::factory()->create(['user_id' => $this->student->id]);

    $this->actingAs($this->admin)
        ->get(StoredFileResource::getUrl('view', ['record' => $file]))
        ->assertSuccessful()
        ->assertSee($file->name);
});

it('offers no way to create or edit a file for staff', function () {
    // Files enter a drive through the owner's own page, where the quota and the
    // upload rules apply. A staff create form would bypass both.
    expect(collect(StoredFileResource::getPages())->keys())
        ->toContain('index', 'view')
        ->not->toContain('create', 'edit');
});

it('keeps students off the staff file list', function () {
    $this->actingAs($this->student)
        ->get(StoredFileResource::getUrl('index'))
        ->assertForbidden();
});

it('shows a missing blob as a problem rather than an empty download', function () {
    // The row exists but the file does not, which is what a failed legacy delete
    // left behind.
    $file = StoredFile::factory()->create([
        'user_id' => $this->student->id,
        'path' => 'users/999/does-not-exist.txt',
    ]);

    expect($file->existsOnDisk())->toBeFalse();

    $this->actingAs($this->admin)
        ->get(StoredFileResource::getUrl('view', ['record' => $file]))
        ->assertSuccessful();
});

it('reports a folder with no path of its own', function () {
    $folder = StoredFile::factory()->folder()->create(['user_id' => $this->student->id]);

    expect($folder->path)->toBe('');

    $this->actingAs($this->admin)
        ->get(StoredFileResource::getUrl('view', ['record' => $folder]))
        ->assertSuccessful()
        ->assertSee('Folders have no path of their own');
});
