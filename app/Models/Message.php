<?php

namespace App\Models;

use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Direct messages.
 *
 * NOTE: both the admin and student "Messages" navigation entries in the legacy
 * UI rendered group chat, not this table — nothing in the legacy frontend read
 * it. See docs/migration/README.md §7 before building UI on top of it.
 */
#[Fillable(['sender_id', 'subject', 'body', 'is_broadcast', 'parent_id'])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_broadcast' => 'boolean',
        ];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /** Threading: the message this one replies to. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class);
    }

    public function recipients(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'message_recipients')
            ->withPivot('read_at')
            ->withTimestamps();
    }

    #[Scope]
    public function broadcasts(Builder $query): void
    {
        $query->where('is_broadcast', true);
    }

    /**
     * Messages this user is allowed to see: broadcasts, plus anything they were
     * sent directly.
     *
     * A broadcast counts even without a recipient row, because a student who
     * enrolled after it was sent should still see it. That is also why
     * markReadBy() attaches a row when one is missing.
     */
    #[Scope]
    public function visibleTo(Builder $query, User $user): void
    {
        $query->where(fn (Builder $visible) => $visible
            ->broadcasts()
            ->orWhereHas('recipients', fn (Builder $recipients) => $recipients->whereKey($user->id)));
    }

    /**
     * The unread half of visibleTo(): the user has no read receipt for these.
     *
     * Kept beside visibleTo() so the inbox query and its unread count can never
     * disagree about who can see what.
     */
    #[Scope]
    public function unreadBy(Builder $query, User $user): void
    {
        $query->whereDoesntHave('recipients', fn (Builder $read) => $read
            ->whereKey($user->id)
            ->whereNotNull('read_at'));
    }

    // -----------------------------------------------------------------
    // Behaviour
    // -----------------------------------------------------------------

    /**
     * Send a message to one or more recipients.
     *
     * @param  array<int, int>  $recipientIds
     */
    public function sendTo(array $recipientIds, bool $isBroadcast = false): self
    {
        $this->forceFill(['is_broadcast' => $isBroadcast])->save();

        $this->recipients()->syncWithoutDetaching($recipientIds);

        return $this;
    }

    /**
     * Record a read receipt, attaching the recipient row when it is missing.
     *
     * updateExistingPivot() alone would silently do nothing for a broadcast the
     * reader has no pivot row for, leaving it unread for good.
     */
    public function markReadBy(User $user): void
    {
        $this->recipients()->syncWithoutDetaching([
            $user->id => ['read_at' => now()],
        ]);
    }

    public function isReadBy(User $user): bool
    {
        return $this->recipients
            ->firstWhere('id', $user->id)?->pivot?->read_at !== null;
    }
}
