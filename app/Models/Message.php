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

    public function markReadBy(User $user): void
    {
        $this->recipients()->updateExistingPivot($user->id, ['read_at' => now()]);
    }
}
