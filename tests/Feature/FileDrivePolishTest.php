<?php

use App\Filament\Student\Pages\MyDrive;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\FileService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use ZipArchive;

beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->student()->create();
    $this->service = app(FileService::class);
});

function txt(string $name, string $body = 'x'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $body);
}

// -----------------------------------------------------------------
// Rename
// -----------------------------------------------------------------

it('renames a file', function () {
    $file = $this->service->store(txt('notes.txt'), $this->user);

    $this->actingAs($this->user);
    $this->service->rename($file, 'lecture-notes.txt');

    $file->refresh();

    expect($file->name)->toBe('lecture-notes.txt')
        // A download is labelled with original_name, so it has to follow.
        ->and($file->original_name)->toBe('lecture-notes.txt')
        // The stored path is derived from the tree and a UUID, never the name, so
        // renaming cannot orphan the blob.
        ->and(Storage::disk('local')->exists($file->path))->toBeTrue();
});

it('renames a folder without disturbing its children', function () {
    $folder = $this->service->createFolder($this->user, 'Labs');
    $file = $this->service->store(txt('deep.txt'), $this->user, $folder);
    $pathBefore = $file->path;

    $this->actingAs($this->user);
    $this->service->rename($folder, 'Practical Work');

    expect($folder->refresh()->name)->toBe('Practical Work')
        ->and($file->fresh()->path)->toBe($pathBefore)
        ->and(Storage::disk('local')->exists($pathBefore))->toBeTrue();
});

it('refuses a rename that would collide with a sibling', function () {
    $this->service->createFolder($this->user, 'Notes');
    $this->service->createFolder($this->user, 'Archive');
    $archive = StoredFile::query()->folders()->where('name', 'Archive')->firstOrFail();

    $this->actingAs($this->user);

    $this->service->rename($archive, 'Notes');
})->throws(ValidationException::class, 'already exists');

it('refuses to rename a file into somebody else\'s drive', function () {
    $theirs = StoredFile::factory()->create();

    $this->actingAs($this->user);

    $this->service->rename($theirs, 'stolen.txt');
})->throws(AuthorizationException::class);

it('renames through the page', function () {
    $file = $this->service->store(txt('notes.txt'), $this->user);

    $this->actingAs($this->user);

    livewire(MyDrive::class)
        ->callAction(TestAction::make('rename')->table($file), ['name' => 'renamed.txt'])
        ->assertHasNoActionErrors();

    expect($file->fresh()->name)->toBe('renamed.txt');
});

// -----------------------------------------------------------------
// Folder as ZIP
// -----------------------------------------------------------------

it('downloads a folder as a ZIP that keeps the tree', function () {
    $folder = $this->service->createFolder($this->user, 'Labs');
    $this->service->store(txt('top.txt', 'top level'), $this->user, $folder);

    $nested = $this->service->createFolder($this->user, 'Week 1', $folder);
    $this->service->store(txt('nested.txt', 'one week in'), $this->user, $nested);

    $this->actingAs($this->user);

    $response = $this->service->zipFolderContents($folder);

    expect($response->headers->get('content-disposition'))->toContain('labs.zip');

    // The archive is streamed, so read it back to check the entries rather than
    // trusting that it was built.
    ob_start();
    $response->sendContent();
    $body = ob_get_clean();

    $zip = new ZipArchive;
    $temporary = tempnam(sys_get_temp_dir(), 'koben-test-');
    file_put_contents($temporary, $body);
    $zip->open($temporary);

    expect($zip->numFiles)->toBe(2)
        ->and($zip->getFromName('Labs/top.txt'))->toBe('top level')
        // Nested paths come from folder names, so an extracted archive mirrors
        // what the user saw in the drive.
        ->and($zip->getFromName('Labs/Week 1/nested.txt'))->toBe('one week in');

    $zip->close();
    unlink($temporary);
});

it('skips a file whose blob is missing rather than producing a broken archive', function () {
    $folder = $this->service->createFolder($this->user, 'Labs');
    $present = $this->service->store(txt('present.txt'), $this->user, $folder);

    // A row with no blob behind it is what a failed legacy delete leaves.
    $ghost = $this->service->store(txt('ghost.txt'), $this->user, $folder);
    Storage::disk('local')->delete($ghost->path);

    $this->actingAs($this->user);

    $response = $this->service->zipFolderContents($folder);

    ob_start();
    $response->sendContent();
    $body = ob_get_clean();

    $zip = new ZipArchive;
    $temporary = tempnam(sys_get_temp_dir(), 'koben-test-');
    file_put_contents($temporary, $body);
    $zip->open($temporary);

    // The archive still opens; an entry pointing at nothing would break extraction
    // of the whole thing.
    expect($zip->getFromName('Labs/present.txt'))->toBe('x')
        ->and($zip->getFromName('Labs/ghost.txt'))->toBeFalse();

    $zip->close();
    unlink($temporary);
});

it('will not zip a single file', function () {
    $file = $this->service->store(txt('notes.txt'), $this->user);

    $this->actingAs($this->user);

    $this->service->zipFolderContents($file);
})->throws(ValidationException::class, 'Only a folder');

it('will not zip somebody else\'s folder', function () {
    $theirs = StoredFile::factory()->folder()->create();

    $this->actingAs($this->user);

    $this->service->zipFolderContents($theirs);
})->throws(AuthorizationException::class);

// -----------------------------------------------------------------
// List / grid toggle
// -----------------------------------------------------------------

it('starts as a list', function () {
    $this->actingAs($this->user);

    livewire(MyDrive::class)->assertSet('viewMode', 'list');
});

it('switches to a grid and back', function () {
    $this->actingAs($this->user);

    livewire(MyDrive::class)
        ->callAction(TestAction::make('toggleViewMode'))
        ->assertSet('viewMode', 'grid')
        ->callAction(TestAction::make('toggleViewMode'))
        ->assertSet('viewMode', 'list');
});

it('shows a preview column only in grid view', function () {
    StoredFile::factory()->image()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user);

    // The preview column exists in both views but is only rendered in grid, so
    // this asserts visibility rather than existence.
    livewire(MyDrive::class)
        ->assertTableColumnExists('mime_type')
        ->assertTableColumnHidden('preview');

    livewire(MyDrive::class)
        ->set('viewMode', 'grid')
        ->assertSet('viewMode', 'grid')
        ->assertTableColumnExists('preview')
        ->assertTableColumnHidden('mime_type')
        ->assertTableColumnHidden('created_at');
});

// -----------------------------------------------------------------
// Search and sorting
// -----------------------------------------------------------------

it('searches the current folder by name', function () {
    $needle = StoredFile::factory()->create(['user_id' => $this->user->id, 'name' => 'lecture-notes.txt']);
    $other = StoredFile::factory()->create(['user_id' => $this->user->id, 'name' => 'shopping-list.txt']);

    $this->actingAs($this->user);

    livewire(MyDrive::class)
        ->searchTable('lecture')
        ->assertCanSeeTableRecords([$needle])
        ->assertCanNotSeeTableRecords([$other]);
});

it('sorts by size when the header is clicked', function () {
    $small = StoredFile::factory()->create(['user_id' => $this->user->id, 'size' => 1024, 'name' => 'small.txt']);
    $large = StoredFile::factory()->create(['user_id' => $this->user->id, 'size' => 5 * 1024 * 1024, 'name' => 'large.txt']);

    $this->actingAs($this->user);

    // Sorting keys off the *column* name, not the column it maps onto, so the
    // control is "human_size" even though it sorts the size column.
    livewire(MyDrive::class)
        ->sortTable('human_size', 'asc')
        ->assertSet('tableSort', 'human_size:asc');

    $ordered = livewire(MyDrive::class)
        ->sortTable('human_size', 'asc')
        ->instance()
        ->getTable()
        ->getRecords();

    expect($ordered->pluck('id')->all())->toBe([$small->id, $large->id]);
});

it('puts folders first by default', function () {
    $file = StoredFile::factory()->create(['user_id' => $this->user->id, 'name' => 'aaa.txt']);
    $folder = StoredFile::factory()->folder()->create(['user_id' => $this->user->id, 'name' => 'zzz']);

    $this->actingAs($this->user);

    $records = livewire(MyDrive::class)
        ->instance()
        ->getTable()
        ->getRecords();

    // Alphabetically the file comes first, so this proves the folder-first
    // default ordering rather than just the name sort.
    expect($records->pluck('id')->all())->toBe([$folder->id, $file->id]);
});

// -----------------------------------------------------------------
// Upload reporting
// -----------------------------------------------------------------

it('reports a partial batch instead of discarding the good files', function () {
    $this->actingAs($this->user);

    livewire(MyDrive::class)
        ->set('uploadState', [txt('good.txt'), txt('bad.exe'), txt('also-good.txt')])
        ->callAction(TestAction::make('upload'))
        ->assertNotified();

    // The good files survive the rejection; the alternative is that one bad file in
    // a batch silently discards the rest.
    expect(StoredFile::query()->whereNotNull('parent_id')->count())->toBe(0);

    $names = StoredFile::query()->pluck('name')->all();
    expect($names)->toContain('good.txt', 'also-good.txt')
        ->not->toContain('bad.exe');
});

it('says so when nothing at all could be uploaded', function () {
    $this->actingAs($this->user);

    livewire(MyDrive::class)
        ->set('uploadState', [txt('bad.exe')])
        ->callAction(TestAction::make('upload'))
        ->assertNotified();

    expect(StoredFile::query()->count())->toBe(0);
});

it('will not send with nothing picked', function () {
    $this->actingAs($this->user);

    livewire(MyDrive::class)
        ->set('uploadState', [])
        ->assertActionDisabled(TestAction::make('upload'));
});
