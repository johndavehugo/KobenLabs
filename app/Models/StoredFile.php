<?php

namespace App\Models;

use App\Support\Bytes;
use Database\Factories\StoredFileFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * A node in a user's personal file drive.
 *
 * Folders and files share this table; `is_folder` distinguishes them. Named
 * `StoredFile` rather than `File` to avoid colliding with the
 * `Illuminate\Support\Facades\File` facade, which is imported almost
 * everywhere in this app.
 *
 * The `path` column is a disk-relative location generated from the folder tree.
 * It is never taken from client input — the legacy implementation accepted a
 * `folder_path` parameter and let the physical layout drift out of sync with the
 * database.
 */
#[Fillable(['user_id', 'name', 'original_name', 'path', 'size', 'mime_type', 'parent_id', 'is_folder'])]
class StoredFile extends Model
{
    /** @use HasFactory<StoredFileFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_folder' => 'boolean',
            'size' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    #[Scope]
    public function files(Builder $query): void
    {
        $query->where('is_folder', false);
    }

    #[Scope]
    public function folders(Builder $query): void
    {
        $query->where('is_folder', true);
    }

    #[Scope]
    public function roots(Builder $query): void
    {
        $query->whereNull('parent_id');
    }

    // -----------------------------------------------------------------
    // Derived values
    // -----------------------------------------------------------------

    protected function humanSize(): Attribute
    {
        return Attribute::get(fn (): string => Bytes::format((int) $this->size));
    }

    public function disk(): Filesystem
    {
        return Storage::disk(config('lab.storage.default_disk'));
    }

    public function existsOnDisk(): bool
    {
        return ! $this->is_folder && $this->disk()->exists($this->path);
    }

    public function isImage(): bool
    {
        return ! $this->is_folder && str_starts_with((string) $this->mime_type, 'image/');
    }

    // -----------------------------------------------------------------
    // Tree traversal
    // -----------------------------------------------------------------

    /**
     * Ancestor chain from the root down to (but excluding) this node, for
     * breadcrumb rendering. Guarded against a cycle from bad data.
     *
     * @return Collection<int, self>
     */
    public function ancestors(): Collection
    {
        $chain = collect();
        $node = $this->parent;
        $guard = 0;

        while ($node !== null && $guard++ < 50) {
            $chain->prepend($node);
            $node = $node->parent;
        }

        return $chain;
    }

    /**
     * @return array<int, int>
     */
    public function descendantIds(): array
    {
        $ids = [];
        $frontier = [$this->id];
        $guard = 0;

        while ($frontier !== [] && $guard++ < 50) {
            $children = static::query()
                ->whereIn('parent_id', $frontier)
                ->pluck('id')
                ->all();

            if ($children === []) {
                break;
            }

            $ids = array_merge($ids, $children);
            $frontier = $children;
        }

        return $ids;
    }

    // -----------------------------------------------------------------
    // Behaviour
    // -----------------------------------------------------------------

    /**
     * Remove a node and everything beneath it, in the database and on disk.
     *
     * The foreign key cascades the rows; this also collects the physical blobs,
     * which the legacy implementation left behind.
     *
     * @return int Number of nodes removed.
     */
    public function deleteTree(): int
    {
        // Includes $this: descendantIds() deliberately excludes the node itself,
        // so sweeping only descendants leaked the blob whenever a plain file —
        // which has no children — was deleted.
        $affectedIds = [$this->id, ...$this->descendantIds()];

        $blobs = static::query()
            ->whereIn('id', $affectedIds)
            ->files()
            ->pluck('path')
            ->all();

        $count = count($affectedIds);

        $this->delete();

        $disk = $this->disk();
        foreach ($blobs as $path) {
            $disk->delete($path);
        }

        return $count;
    }
}
