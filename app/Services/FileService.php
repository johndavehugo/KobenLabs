<?php

namespace App\Services;

use App\Enums\GroupMessageType;
use App\Models\GroupChat;
use App\Models\GroupChatMessage;
use App\Models\MessageAttachment;
use App\Models\StoredFile;
use App\Models\User;
use App\Support\Bytes;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/**
 * Everything that touches the file drive.
 *
 * The legacy app spread this across `FileService`, `FileController` and
 * `GroupChatController` with three separate validation policies, and the three
 * disagreed:
 *
 *   - user files: 50 MB, and `ALLOWED_EXTENSIONS` plus a 30-entry MIME list were
 *     declared and never invoked — no type restriction at all
 *   - message attachments: 50 MB, explicit comment "allow all file types"
 *   - group chat files: 10 MB, the only real allowlist, and it checked
 *     `$_FILES['type']`, which is client-supplied and trivially spoofed
 *
 * Quota was enforced only on user file upload, so message and group chat uploads
 * consumed space that was never counted.
 *
 * This service is the single gate. Every upload path goes through `assertAllowed`
 * and `assertWithinQuota`.
 */
class FileService
{
    /**
     * Extensions that must never be stored, whatever the allowlist says.
     *
     * Checked against the *stored* extension as well as the sniffed MIME, so a
     * `.png` that is actually PHP cannot be written and then served.
     *
     * @var array<int, string>
     */
    private const DANGEROUS_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phtml', 'pht',
        'phar', 'inc',
        'exe', 'dll', 'com', 'scr', 'msi', 'bat', 'cmd', 'pif',
        'js', 'mjs', 'cjs', 'jse', 'wsf', 'wsh', 'ps1', 'psm1', 'vbs', 'vbe',
        'sh', 'bash', 'zsh', 'csh', 'ksh',
        'py', 'pyc', 'pyo', 'rb', 'pl', 'cgi',
        'jar', 'war', 'ear', 'class',
        'htaccess', 'htpasswd', 'ini', 'env', 'config',
        'svg', 'html', 'htm', 'xhtml', 'xml',
    ];

    /**
     * Sniffed content types that indicate executable content.
     *
     * An allowlist keyed purely on MIME would reject legitimate office documents
     * — `.doc` sniffs as `application/msword`, `application/vnd.ms-word` or
     * `application/x-ole-storage` depending on the bytes — so the MIME check is a
     * denylist and the extension allowlist does the narrowing.
     *
     * @var array<int, string>
     */
    private const DANGEROUS_MIME_TYPES = [
        'text/x-php', 'application/x-php', 'application/x-httpd-php',
        'application/x-httpd-php-source', 'text/x-shellscript',
        'application/x-executable', 'application/x-sharedlib',
        'application/x-msdownload', 'application/x-msdos-program',
        'application/x-dosexec', 'application/java-archive',
        'application/x-bat', 'application/x-sh', 'application/x-csh',
        'application/javascript', 'text/javascript', 'application/x-python',
        'application/x-python-code', 'text/x-python',
    ];

    // -----------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------

    /**
     * Sniff the uploaded bytes with finfo.
     *
     * Deliberately not `$file->getMimeType()`: that resolves from the file *name*
     * under `Illuminate\Http\Testing\File`, so the security check would be
     * validating a test fixture rather than the payload. Reading the bytes keeps
     * the guarantee honest in both environments.
     */
    private function sniffMimeType(UploadedFile $file): ?string
    {
        $path = $file->getRealPath() ?: $file->getPathname();

        if ($path === '' || ! is_readable($path)) {
            return null;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);

        return is_string($mime) && $mime !== '' ? $mime : null;
    }

    /**
     * Reject anything outside the allowlist, too large, or that sniffs as
     * executable.
     *
     * @param  array<int, string>|null  $allowedExtensions
     *
     * @throws ValidationException
     */
    public function assertAllowed(UploadedFile $file, ?array $allowedExtensions = null, ?int $maxKilobytes = null): void
    {
        $allowedExtensions ??= (array) config('lab.uploads.allowed_extensions');
        $maxKilobytes ??= (int) config('lab.uploads.max_kilobytes');

        if ($maxKilobytes > 0 && $file->getSize() > $maxKilobytes * 1024) {
            throw ValidationException::withMessages([
                'file' => 'That file exceeds the '.intdiv($maxKilobytes, 1024).' MB limit.',
            ]);
        }

        $extension = strtolower($file->getClientOriginalExtension());

        if ($extension === '' || ! in_array($extension, $allowedExtensions, true)) {
            throw ValidationException::withMessages([
                'file' => $this->extensionMessage($extension),
            ]);
        }

        if (in_array($extension, self::DANGEROUS_EXTENSIONS, true)) {
            throw ValidationException::withMessages([
                'file' => "Files of type '.{$extension}' cannot be stored.",
            ]);
        }

        // The bytes, not the name and not $_FILES['type'], which the client
        // controls. The legacy group chat check trusted the latter.
        $mimeType = strtolower((string) $this->sniffMimeType($file));

        if (in_array($mimeType, self::DANGEROUS_MIME_TYPES, true)) {
            throw ValidationException::withMessages([
                'file' => "The file's contents look executable, so it cannot be stored (detected {$mimeType}).",
            ]);
        }
    }

    /**
     * Reject anything that would push the user past their quota.
     *
     * Legacy only counted user file uploads, so message and group chat attachments
     * consumed space that was never counted against anybody. Every write path now
     * comes through here.
     *
     * @throws ValidationException
     */
    public function assertWithinQuota(User $user, int $additionalBytes): void
    {
        $quota = $user->storageQuotaBytes();

        // A zero quota means unlimited rather than nothing.
        if ($quota <= 0) {
            return;
        }

        // Recomputed rather than reading storage_used_mb, which drifts: legacy
        // never decremented it for message or group chat files.
        $used = (int) $user->files()->files()->sum('size');

        if ($used + $additionalBytes <= $quota) {
            return;
        }

        $remaining = max(0, $quota - $used);

        throw ValidationException::withMessages([
            'file' => 'That upload would exceed your storage quota. '
                .$this->formatBytes($remaining).' remaining of '.$this->formatBytes($quota).'.',
        ]);
    }

    // -----------------------------------------------------------------
    // Writing
    // -----------------------------------------------------------------

    /**
     * Store an uploaded file inside a user's drive.
     *
     * @param  array<int, string>|null  $allowedExtensions
     */
    public function store(UploadedFile $file, User $user, ?StoredFile $parent = null, ?array $allowedExtensions = null): StoredFile
    {
        $this->assertOwnedParent($parent, $user);
        $this->assertAllowed($file, $allowedExtensions);
        $this->assertWithinQuota($user, $file->getSize());

        $extension = strtolower($file->getClientOriginalExtension());
        $relativePath = $this->relativePathFor($user, $parent, $extension);

        $disk = $this->disk();
        $disk->put($relativePath, (string) file_get_contents($file->getRealPath()));

        try {
            $stored = $user->files()->create([
                'name' => $this->displayName($file->getClientOriginalName()),
                'original_name' => $this->displayName($file->getClientOriginalName()),
                'path' => $relativePath,
                'size' => $file->getSize(),
                // The sniffed type, not the client-supplied one.
                'mime_type' => $this->sniffMimeType($file),
                'parent_id' => $parent?->id,
                'is_folder' => false,
            ]);
        } catch (\Throwable $exception) {
            // Never leave an orphaned blob behind if the row fails to write.
            $disk->delete($relativePath);

            throw $exception;
        }

        // Recomputed rather than incremented, so the counter cannot drift the way
        // the legacy storage_used_mb column did.
        $user->recalculateStorageUsed();

        return $stored;
    }

    /**
     * Create a folder. Folders are rows, not directories on disk — the tree
     * lives in the database and paths are generated from it.
     */
    public function createFolder(User $user, string $name, ?StoredFile $parent = null): StoredFile
    {
        $this->assertOwnedParent($parent, $user);

        $name = $this->displayName($name);

        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => 'A folder needs a name.',
            ]);
        }

        // where('parent_id', null) compiles to `parent_id = null`, which never
        // matches — so two root folders looked like a name clash.
        $siblings = $user->files()
            ->where(fn ($query) => $parent === null
                ? $query->whereNull('parent_id')
                : $query->where('parent_id', $parent->id));

        if ((clone $siblings)->where('name', $name)->exists()) {
            throw ValidationException::withMessages([
                'name' => 'Something with that name already exists here.',
            ]);
        }

        return $user->files()->create([
            'name' => $name,
            'original_name' => $name,
            'path' => '',
            'size' => 0,
            'mime_type' => 'folder',
            'parent_id' => $parent?->id,
            'is_folder' => true,
        ]);
    }

    /**
     * Rename a node.
     *
     * Only the label changes. The stored path is derived from the folder tree and a
     * UUID, never from the name, so renaming cannot orphan a file — which is why the
     * legacy rename endpoint could safely have existed but did not.
     *
     * @throws ValidationException
     */
    public function rename(StoredFile $file, string $name): StoredFile
    {
        Gate::authorize('update', $file);

        $name = $this->displayName($name);

        if ($name === '' || $name === $file->name) {
            return $file;
        }

        $clash = StoredFile::query()
            ->where('user_id', $file->user_id)
            ->where('parent_id', $file->parent_id)
            ->where('name', $name)
            ->whereKeyNot($file->getKey())
            ->exists();

        if ($clash) {
            throw ValidationException::withMessages([
                'name' => 'Something with that name already exists here.',
            ]);
        }

        $file->forceFill([
            'name' => $name,
            // original_name is what a download is labelled with, so it follows a rename
            // for files. For folders it is unused and left alone.
            'original_name' => $file->is_folder ? $file->original_name : $name,
        ])->save();

        return $file;
    }

    /**
     * Bundle a folder's contents into a ZIP for download.
     *
     * Writes to a temporary stream rather than to the disk, so a large folder does not
     * leave an archive behind if the response is abandoned halfway.
     */
    public function zipFolderContents(StoredFile $folder): StreamedResponse
    {
        Gate::authorize('view', $folder);

        if (! $folder->is_folder) {
            throw ValidationException::withMessages([
                'folder' => 'Only a folder can be downloaded as a ZIP.',
            ]);
        }

        $disk = $this->disk();
        $archiveName = Str::slug($folder->name).'.zip';

        return response()->streamDownload(function () use ($folder, $disk): void {
            $zip = new ZipArchive;
            $temporary = tempnam(sys_get_temp_dir(), 'koben-zip-');

            $zip->open($temporary, ZipArchive::OVERWRITE | ZipArchive::CREATE);

            foreach ($this->collectFiles($folder) as $path => $diskPath) {
                // Only add if the blob is actually there: an entry that points at
                // nothing would make the archive fail to extract at all.
                if ($disk->exists($diskPath)) {
                    $zip->addFromString($path, (string) $disk->get($diskPath));
                }
            }

            $zip->close();

            readfile($temporary);
            @unlink($temporary);
        }, $archiveName, ['Content-Type' => 'application/zip']);
    }

    /**
     * Every file beneath a folder, keyed by its path relative to the folder.
     *
     * Paths are built from names, not from stored paths, because the entry names
     * inside the archive are what a user sees when they extract it.
     *
     * @return array<string, string>
     */
    private function collectFiles(StoredFile $folder): array
    {
        $entries = [];

        // Breadth-first with an explicit [prefix, node] pair: a plain
        // name => model map would come back from array_shift() with a string key,
        // which list destructuring cannot unpack.
        $queue = [[$folder->name, $folder]];

        while ($queue !== []) {
            [$prefix, $node] = array_shift($queue);

            foreach ($node->children()->orderBy('name')->get() as $child) {
                $path = $prefix.'/'.$child->name;

                if ($child->is_folder) {
                    $queue[] = [$path, $child];

                    continue;
                }

                $entries[$path] = $child->path;
            }
        }

        return $entries;
    }

    /**
     * Bundle a node and everything beneath it, rows and blobs, then resync usage.
     *
     * The traversal lives on `StoredFile`; this wrapper adds the authorization and
     * the accounting, so no caller can forget either.
     *
     * @return int Number of nodes removed.
     */
    public function deleteTree(StoredFile $file): int
    {
        // store() and createFolder() both verify ownership, so deletion has to as
        // well — otherwise this method is the one place in the service that will
        // happily remove somebody else's file.
        Gate::authorize('delete', $file);

        $ownerId = $file->user_id;

        $count = $file->deleteTree();

        User::query()->find($ownerId)?->recalculateStorageUsed();

        return $count;
    }

    // -----------------------------------------------------------------
    // Message and group chat attachments
    // -----------------------------------------------------------------

    /**
     * Store an attachment for a direct message.
     *
     * Previously `Message::addAttachment()` omitted the NOT NULL
     * `original_filename` column, so the insert always failed — the feature was
     * never exercised. Quota is now checked here too, which legacy did not do.
     */
    public function storeMessageAttachment(UploadedFile $file, User $user, int $messageId): MessageAttachment
    {
        $maxKilobytes = (int) config('lab.messages.max_attachment_kilobytes');

        if ($file->getSize() > $maxKilobytes * 1024) {
            throw ValidationException::withMessages([
                'file' => 'That attachment exceeds the '.intdiv($maxKilobytes, 1024).' MB limit.',
            ]);
        }

        $this->assertAllowed($file);
        $this->assertWithinQuota($user, $file->getSize());

        $relativePath = sprintf('messages/%d/%s', $messageId, Str::uuid7().'.'.strtolower($file->getClientOriginalExtension()));

        $this->disk()->put($relativePath, (string) file_get_contents($file->getRealPath()));

        return MessageAttachment::query()->create([
            'message_id' => $messageId,
            'original_name' => $this->displayName($file->getClientOriginalName()),
            'path' => $relativePath,
            'size' => $file->getSize(),
            'mime_type' => $this->sniffMimeType($file),
        ]);
    }

    /**
     * Store a group chat attachment.
     *
     * Group chat keeps its own stricter extension list because that was the only
     * zone in the legacy app with a real allowlist — but the check now reads the
     * file's contents rather than `$_FILES['type']`.
     */
    public function storeGroupChatFile(UploadedFile $file, User $user, GroupChat $group): GroupChatMessage
    {
        Gate::authorize('view', $group);

        $settings = (array) config('lab.uploads.group_chat');
        $maxKilobytes = (int) $settings['max_kilobytes'];

        if ($file->getSize() > $maxKilobytes * 1024) {
            throw ValidationException::withMessages([
                'file' => 'That file exceeds the '.intdiv($maxKilobytes, 1024).' MB limit for group chat.',
            ]);
        }

        $this->assertAllowed($file, (array) $settings['allowed_extensions']);
        $this->assertWithinQuota($user, $file->getSize());

        $extension = strtolower($file->getClientOriginalExtension());
        $relativePath = sprintf('group-chats/%d/%s.%s', $group->id, Str::uuid7(), $extension);

        $this->disk()->put($relativePath, (string) file_get_contents($file->getRealPath()));

        return $group->chatMessages()->create([
            'sender_id' => $user->id,
            'body' => '',
            'type' => GroupMessageType::File,
            'file_path' => $relativePath,
            'file_name' => $this->displayName($file->getClientOriginalName()),
            'file_size' => $file->getSize(),
        ]);
    }

    // -----------------------------------------------------------------
    // Paths
    // -----------------------------------------------------------------

    /**
     * Build a disk-relative path from the folder tree.
     *
     * Never derived from a client-supplied `folder_path`. The legacy service
     * accepted one and then wrote `uploads/users/{userId}/{unique}` regardless,
     * so the physical layout and the database tree could disagree.
     */
    public function relativePathFor(User $user, ?StoredFile $parent, string $extension): string
    {
        $segments = ['users', (string) $user->id];

        if ($parent !== null) {
            $segments = [...$segments, ...$this->treeSegments($parent)];
        }

        $extension = strtolower(trim($extension, '.'));

        return implode('/', array_filter([...$segments, Str::uuid7().($extension === '' ? '' : '.'.$extension)]));
    }

    /**
     * Folder ids converted to short opaque segments.
     *
     * Using ids keeps the mapping derivable from the database alone, so a moved
     * folder does not orphan its children.
     *
     * @return array<int, string>
     */
    private function treeSegments(StoredFile $parent): array
    {
        return $parent->ancestors()
            ->push($parent)
            ->map(fn (StoredFile $folder) => $this->segmentFor($folder))
            ->all();
    }

    private function segmentFor(StoredFile $folder): string
    {
        return 'd'.base_convert((string) $folder->id, 10, 36);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('lab.storage.default_disk'));
    }

    /**
     * A folder's parent must belong to the same user, or the tree could be grafted
     * across accounts.
     *
     * @throws ValidationException
     */
    private function assertOwnedParent(?StoredFile $parent, User $user): void
    {
        if ($parent === null) {
            return;
        }

        if ($parent->user_id !== $user->id) {
            throw ValidationException::withMessages([
                'parent_id' => 'That folder does not belong to you.',
            ]);
        }
    }

    /**
     * Reduce a client filename to something safe to store and display.
     *
     * The original name is metadata only; the stored path never contains it.
     */
    private function displayName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? $name;
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            return 'file';
        }

        return Str::limit($name, 255, '');
    }

    private function extensionMessage(string $extension): string
    {
        $allowed = implode(', ', (array) config('lab.uploads.allowed_extensions'));

        return $extension === ''
            ? 'That file type cannot be stored. Allowed: '.$allowed.'.'
            : "'.{$extension}' files cannot be stored. Allowed: {$allowed}.";
    }

    private function extensionFromMime(UploadedFile $file): ?string
    {
        return $file->guessExtension();
    }

    public function formatBytes(int $bytes): string
    {
        return Bytes::format($bytes);
    }
}
