<?php

use App\Filament\Student\Pages\MyDrive;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\FileService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->student()->create();
    $this->service = app(FileService::class);
});

it('serves the drive page to a student', function () {
    $this->actingAs($this->user)
        ->get(MyDrive::getUrl(panel: 'student'))
        ->assertSuccessful();
});

it('keeps staff off the student drive page', function () {
    // Panel gating, so this has to be an HTTP-level assertion — Filament enforces
    // canAccessPanel() in middleware, not on the bare Livewire component.
    $this->actingAs(User::factory()->teacher()->create())
        ->get(MyDrive::getUrl(panel: 'student'))
        ->assertForbidden();
});

it('starts at the drive root', function () {
    $this->actingAs($this->user);

    livewire(MyDrive::class)
        ->assertSet('folderId', null)
        ->assertCanSeeTableRecords([]);
});

// -----------------------------------------------------------------
// Folder browsing
// -----------------------------------------------------------------

it('lists the contents of the current folder only', function () {
    $folder = StoredFile::factory()->folder()->create(['user_id' => $this->user->id]);
    $nested = StoredFile::factory()->inside($folder)->create();
    $sibling = StoredFile::factory()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user);

    // At the root: the folder and the sibling, but not what is inside the folder.
    livewire(MyDrive::class)
        ->assertSet('folderId', null)
        ->assertCanSeeTableRecords([$folder, $sibling])
        ->assertCanNotSeeTableRecords([$nested]);
});

it('lists only the contents of the folder being viewed', function () {
    $folder = StoredFile::factory()->folder()->create(['user_id' => $this->user->id]);
    $nested = StoredFile::factory()->inside($folder)->create();

    $this->actingAs($this->user);

    livewire(MyDrive::class)
        ->set('folderId', $folder->id)
        ->assertCanSeeTableRecords([$nested])
        ->assertCanNotSeeTableRecords([$folder]);
});

it('drills into a folder', function () {
    $folder = StoredFile::factory()->folder()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user);

    livewire(MyDrive::class)
        ->callAction(TestAction::make('open')->table($folder))
        ->assertSet('folderId', $folder->id);
});

it('builds breadcrumbs from the folder chain', function () {
    $outer = $this->service->createFolder($this->user, 'Labs');
    $inner = $this->service->createFolder($this->user, 'Week 1', $outer);

    $this->actingAs($this->user);

    livewire(MyDrive::class)
        ->set('folderId', $inner->id)
        ->assertSee('Labs / Week 1');
});

// -----------------------------------------------------------------
// The important one: a tampered folder id must not browse another account
// -----------------------------------------------------------------

it('ignores a folder id belonging to somebody else', function () {
    $victimFolder = StoredFile::factory()->folder()->create();

    $this->actingAs($this->user);

    livewire(MyDrive::class)
        ->set('folderId', $victimFolder->id)
        ->assertSet('folderId', null);
});

it('shows only the current user\'s files in the table', function () {
    $mine = StoredFile::factory()->create(['user_id' => $this->user->id]);
    $theirs = StoredFile::factory()->create();

    $this->actingAs($this->user);

    livewire(MyDrive::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

// -----------------------------------------------------------------
// Upload
// -----------------------------------------------------------------

it('uploads a file into the current folder', function () {
    $folder = StoredFile::factory()->folder()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user);

    livewire(MyDrive::class)
        ->set('folderId', $folder->id)
        ->set('uploadState', [UploadedFile::fake()->createWithContent('notes.txt', 'hello')])
        ->callAction(TestAction::make('upload'))
        ->assertHasNoActionErrors();

    $stored = StoredFile::query()->where('name', 'notes.txt')->firstOrFail();

    expect($stored->parent_id)->toBe($folder->id)
        ->and($stored->user_id)->toBe($this->user->id);

    Storage::disk('local')->assertExists($stored->path);
});

it('clears the pending upload field once the files are saved', function () {
    $this->actingAs($this->user);

    livewire(MyDrive::class)
        ->set('uploadState', [UploadedFile::fake()->createWithContent('notes.txt', 'hello')])
        ->callAction(TestAction::make('upload'))
        ->assertSet('uploadState', []);
});

it('refuses an upload whose contents are executable', function () {
    $this->actingAs($this->user);

    livewire(MyDrive::class)
        ->set('uploadState', [UploadedFile::fake()->createWithContent('notes.txt', '<?php system($_GET["c"]); ?>')])
        ->callAction(TestAction::make('upload'))
        ->assertNotified();

    expect(StoredFile::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('users'))->toBe([]);
});

it('shows a rejection instead of failing silently', function () {
    $this->actingAs($this->user);

    livewire(MyDrive::class)
        ->set('uploadState', [UploadedFile::fake()->createWithContent('payload.exe', 'x')])
        ->callAction(TestAction::make('upload'))
        ->assertNotified();

    expect(StoredFile::query()->count())->toBe(0);
});

it('refuses an upload that would exceed the quota', function () {
    $this->user->forceFill(['storage_quota_mb' => 1])->save();
    StoredFile::factory()->create(['user_id' => $this->user->id, 'size' => 1024 * 1024]);

    $this->actingAs($this->user->fresh());

    livewire(MyDrive::class)
        ->set('uploadState', [UploadedFile::fake()->createWithContent('notes.txt', 'x')])
        ->callAction(TestAction::make('upload'))
        ->assertNotified();

    expect(StoredFile::query()->where('name', 'notes.txt')->count())->toBe(0);
});

// -----------------------------------------------------------------
// Folders
// -----------------------------------------------------------------

it('creates a folder in the current folder', function () {
    $this->actingAs($this->user);

    livewire(MyDrive::class)
        ->callAction(TestAction::make('createFolder'), ['name' => 'Projects'])
        ->assertHasNoActionErrors();

    expect(StoredFile::query()->where('name', 'Projects')->exists())->toBeTrue();
});

it('reports a duplicate folder name rather than creating a second one', function () {
    StoredFile::factory()->folder()->create(['user_id' => $this->user->id, 'name' => 'Projects']);

    $this->actingAs($this->user);

    livewire(MyDrive::class)
        ->callAction(TestAction::make('createFolder'), ['name' => 'Projects'])
        ->assertNotified();

    expect(StoredFile::query()->folders()->count())->toBe(1);
});

// -----------------------------------------------------------------
// Delete
// -----------------------------------------------------------------

it('deletes a file and its blob', function () {
    $file = $this->service->store(
        UploadedFile::fake()->createWithContent('notes.txt', 'x'),
        $this->user,
    );

    $this->actingAs($this->user);

    livewire(MyDrive::class)
        ->callAction(TestAction::make('delete')->table($file));

    expect(StoredFile::query()->count())->toBe(0)
        ->and(Storage::disk('local')->exists($file->path))->toBeFalse();
});

it('deletes a folder with everything inside it', function () {
    $folder = $this->service->createFolder($this->user, 'Project');
    $file = $this->service->store(
        UploadedFile::fake()->createWithContent('deep.txt', 'x'),
        $this->user,
        $folder,
    );

    $this->actingAs($this->user);

    livewire(MyDrive::class)
        ->callAction(TestAction::make('delete')->table($folder));

    expect(StoredFile::query()->count())->toBe(0)
        ->and(Storage::disk('local')->exists($file->path))->toBeFalse();
});

it('does not list another account\'s file, so it cannot be acted on', function () {
    $theirs = StoredFile::factory()->create();

    $this->actingAs($this->user);

    livewire(MyDrive::class)
        ->assertCanNotSeeTableRecords([$theirs]);

    // The row is still theirs — the page simply never offers a way to touch it.
    expect($this->user->can('delete', $theirs))->toBeFalse()
        ->and(StoredFile::query()->count())->toBe(1);
});

it('refuses to delete another account\'s file even when the action is invoked directly', function () {
    $theirs = StoredFile::factory()->create();

    $this->actingAs($this->user);

    expect(fn () => $this->service->deleteTree($theirs))
        ->toThrow(AuthorizationException::class);

    expect(StoredFile::query()->count())->toBe(1);
});
