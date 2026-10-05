<?php

use App\Models\GroupChat;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\FileService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('local');

    $this->service = app(FileService::class);
    $this->user = User::factory()->student()->create();
});

function upload(string $name, string $contents = 'plain text body'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $contents);
}

function folderSegment(StoredFile $folder): string
{
    return 'd'.base_convert((string) $folder->id, 10, 36);
}

/**
 * Put a user over quota by writing a row directly, so the quota check has
 * something real to find.
 */
function exhaustQuota(User $user, int $megabytes = 1): void
{
    $user->forceFill(['storage_quota_mb' => $megabytes])->save();

    StoredFile::factory()->create([
        'user_id' => $user->id,
        'size' => $megabytes * 1024 * 1024,
    ]);
}

// -----------------------------------------------------------------
// The allowlist legacy declared and never called
// -----------------------------------------------------------------

it('stores an allowed file and records the sniffed mime type', function () {
    $file = $this->service->store(upload('notes.txt', 'hello'), $this->user);

    expect($file->is_folder)->toBeFalse()
        ->and($file->parent_id)->toBeNull()
        ->and($file->name)->toBe('notes.txt')
        ->and($file->mime_type)->toBe('text/plain');

    Storage::disk('local')->assertExists($file->path);
});

it('rejects a file whose extension is outside the allowlist', function () {
    $this->service->store(upload('payload.exe'), $this->user);
})->throws(ValidationException::class, "'.exe' files cannot be stored");

it('rejects an executable disguised with an allowed extension', function () {
    // .png is on the allowlist, so only a content check can catch this.
    // UploadedFile::fake()->getMimeType() resolves from the *name*, so this also
    // proves the check reads the bytes rather than trusting the reported type.
    $this->service->store(
        upload('sneaky.png', '<?php system($_GET["c"]); ?>'),
        $this->user,
    );
})->throws(ValidationException::class, 'executable');

it('rejects a shell script disguised with an allowed extension', function () {
    $this->service->store(upload('notes.txt', "#!/bin/sh\nrm -rf /\n"), $this->user);
})->throws(ValidationException::class, 'executable');

it('rejects a file over the size limit', function () {
    config()->set('lab.uploads.max_kilobytes', 4); // 4 KB

    $this->service->store(upload('big.txt', str_repeat('a', 8192)), $this->user);
})->throws(ValidationException::class, 'exceeds');

it('rejects a group chat file whose contents are executable', function () {
    $this->actingAs($this->user);

    // Legacy group chat validated $_FILES['type'], which the client controls.
    $this->service->storeGroupChatFile(
        upload('notes.txt', '<?php system($_GET["c"]); ?>'),
        $this->user,
        GroupChat::factory()->withMembers($this->user)->create(),
    );
})->throws(ValidationException::class, 'executable');

it('refuses to post into a group the user has not joined', function () {
    $this->actingAs($this->user);

    $this->service->storeGroupChatFile(
        upload('notes.txt', 'hello'),
        $this->user,
        GroupChat::factory()->create(),
    );
})->throws(AuthorizationException::class);

// -----------------------------------------------------------------
// Quota: legacy counted only user uploads
// -----------------------------------------------------------------

it('rejects an upload that would exceed the quota', function () {
    exhaustQuota($this->user, 1);

    $this->service->store(upload('a.txt', 'x'), $this->user);
})->throws(ValidationException::class, 'storage quota');

it('counts message attachments against the quota', function () {
    $this->actingAs($this->user);
    exhaustQuota($this->user, 1);

    // Legacy never counted these, so a user could fill the disk through message
    // attachments while appearing to sit well under quota.
    $this->service->storeMessageAttachment(
        upload('a.txt', 'x'),
        $this->user,
        Message::factory()->create()->id,
    );
})->throws(ValidationException::class, 'storage quota');

it('counts group chat files against the quota', function () {
    $this->actingAs($this->user);
    exhaustQuota($this->user, 1);

    $this->service->storeGroupChatFile(
        upload('a.txt', 'x'),
        $this->user,
        GroupChat::factory()->withMembers($this->user)->create(),
    );
})->throws(ValidationException::class, 'storage quota');

it('resyncs usage after a write instead of incrementing it', function () {
    expect((int) $this->user->fresh()->storage_used_mb)->toBe(0);

    $this->service->store(upload('a.txt', str_repeat('x', 1024 * 1024)), $this->user);

    expect((float) $this->user->fresh()->storage_used_mb)->toBe(1.0);
});

// -----------------------------------------------------------------
// Paths derived from the tree, never from client input
// -----------------------------------------------------------------

it('nests a stored path under its folder chain', function () {
    $root = $this->service->createFolder($this->user, 'Labs');
    $nested = $this->service->createFolder($this->user, 'Week 1', $root);

    $file = $this->service->store(upload('notes.txt', 'x'), $this->user, $nested);

    expect($file->path)->toStartWith('users/'.$this->user->id.'/')
        ->and($file->path)->toContain(folderSegment($root))
        ->and($file->path)->toContain(folderSegment($nested))
        ->and(basename($file->path))->toEndWith('.txt');
});

it('never lets a traversal in a filename escape the user prefix', function () {
    $file = $this->service->store(upload('../../evil.txt', 'x'), $this->user);

    expect($file->path)->toStartWith('users/'.$this->user->id.'/')
        ->and($file->path)->not->toContain('..')
        ->and($file->name)->toBe('evil.txt');
});

it('never lets a traversal in a folder name escape the user prefix', function () {
    $folder = $this->service->createFolder($this->user, '../../etc/passwd');

    expect($folder->name)->not->toContain('..')
        ->and($folder->name)->not->toContain('/');
});

it('refuses to file into a folder belonging to somebody else', function () {
    $victimFolder = StoredFile::factory()->folder()->create();

    $this->service->store(upload('notes.txt', 'x'), $this->user, $victimFolder);
})->throws(ValidationException::class, 'does not belong to you');

it('refuses to create a folder inside somebody else\'s folder', function () {
    $victimFolder = StoredFile::factory()->folder()->create();

    $this->service->createFolder($this->user, 'Stolen', $victimFolder);
})->throws(ValidationException::class, 'does not belong to you');

// -----------------------------------------------------------------
// Deletion: legacy left every child blob on disk
// -----------------------------------------------------------------

it('deletes descendants and their blobs together', function () {
    $this->actingAs($this->user);

    $root = $this->service->createFolder($this->user, 'Project');
    $child = $this->service->createFolder($this->user, 'Reports', $root);
    $file = $this->service->store(upload('deep.txt', 'x'), $this->user, $child);

    Storage::disk('local')->assertExists($file->path);

    // Legacy recursed in the database but unlinked only the top-level file, so
    // this blob would have survived forever.
    expect($this->service->deleteTree($root))->toBe(3)
        ->and(StoredFile::query()->count())->toBe(0)
        ->and(Storage::disk('local')->exists($file->path))->toBeFalse();
});

it('resyncs usage after a delete', function () {
    $this->actingAs($this->user);

    $this->service->store(upload('gone.txt', str_repeat('y', 1024 * 1024)), $this->user);
    expect((float) $this->user->fresh()->storage_used_mb)->toBe(1.0);

    $this->service->deleteTree($this->user->files()->firstOrFail());

    expect((float) $this->user->fresh()->storage_used_mb)->toBe(0.0);
});

it('removes the blob when the database insert fails', function () {
    // Simulates a write that succeeds on disk and then fails in the database —
    // the case that leaks storage forever if the blob is not cleaned up.
    StoredFile::creating(function () {
        throw new RuntimeException('insert blocked');
    });

    try {
        $this->service->store(upload('x.txt', 'y'), $this->user);
    } catch (RuntimeException) {
        // expected
    }

    expect(Storage::disk('local')->allFiles('users'))->toBe([])
        ->and(StoredFile::query()->count())->toBe(0);
});

// -----------------------------------------------------------------
// Folders
// -----------------------------------------------------------------

it('refuses a duplicate folder name in the same place', function () {
    $this->service->createFolder($this->user, 'Notes');

    $this->service->createFolder($this->user, 'Notes');
})->throws(ValidationException::class, 'already exists');

it('allows the same folder name in different places', function () {
    $root = $this->service->createFolder($this->user, 'Notes');
    $nested = $this->service->createFolder($this->user, 'Notes', $root);

    expect($nested->id)->not->toBe($root->id)
        ->and($nested->parent_id)->toBe($root->id);
});

it('allows different folder names side by side', function () {
    $a = $this->service->createFolder($this->user, 'Notes');
    $b = $this->service->createFolder($this->user, 'Archive');

    expect($a->parent_id)->toBeNull()
        ->and($b->parent_id)->toBeNull();
});

// -----------------------------------------------------------------
// Message attachments
// -----------------------------------------------------------------

it('stores a message attachment with its original filename', function () {
    $message = Message::factory()->create();

    $attachment = $this->service->storeMessageAttachment(
        upload('diagram.png', 'fake'),
        $this->user,
        $message->id,
    );

    // Legacy omitted the NOT NULL original_filename column, so this insert
    // always threw and the feature was dead.
    expect($attachment->original_name)->toBe('diagram.png')
        ->and($attachment->path)->toStartWith('messages/'.$message->id.'/')
        ->and($attachment->message_id)->toBe($message->id)
        ->and(MessageAttachment::query()->count())->toBe(1);

    Storage::disk('local')->assertExists($attachment->path);
});

it('rejects an oversized message attachment', function () {
    config()->set('lab.messages.max_attachment_kilobytes', 2); // 2 KB

    $this->service->storeMessageAttachment(
        upload('big.txt', str_repeat('a', 8192)),
        $this->user,
        Message::factory()->create()->id,
    );
})->throws(ValidationException::class, 'exceeds');
