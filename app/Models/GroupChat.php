<?php

namespace App\Models;

use App\Enums\GroupMessageType;
use App\Enums\GroupRole;
use Database\Factories\GroupChatFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A chat group joined by a short shareable code.
 *
 * Membership is a pivot (`group_chat_members`) whose `role` is group-scoped and
 * independent of the user's global role.
 */
#[Fillable(['name', 'description', 'join_code', 'created_by_id', 'is_active'])]
class GroupChat extends Model
{
    /** @use HasFactory<GroupChatFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * Membership. The pivot carries `joined_at` rather than Laravel's default
     * timestamps, so `withTimestamps()` is deliberately omitted.
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'group_chat_members')
            ->withPivot(['role', 'joined_at']);
    }

    public function chatMessages(): HasMany
    {
        return $this->hasMany(GroupChatMessage::class);
    }

    #[Scope]
    public function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    // -----------------------------------------------------------------
    // Behaviour
    // -----------------------------------------------------------------

    /**
     * Generate an unused join code. Uses `Str::random()`; the legacy
     * implementation used `str_shuffle()`, which is not cryptographically
     * strong and produced predictable codes.
     */
    public static function generateJoinCode(): string
    {
        $length = (int) config('lab.group_chats.join_code_length', 8);

        do {
            $code = Str::random($length);
        } while (static::query()->where('join_code', $code)->exists());

        return $code;
    }

    public function isMember(User $user): bool
    {
        return $this->members()->whereKey($user->id)->exists();
    }

    public function roleFor(User $user): ?GroupRole
    {
        $role = $this->members()
            ->whereKey($user->id)
            ->first()
            ?->pivot
            ?->role;

        return $role !== null ? GroupRole::from($role) : null;
    }

    public function join(User $user, GroupRole $role = GroupRole::Member): void
    {
        $this->members()->syncWithoutDetaching([
            $user->id => ['role' => $role->value, 'joined_at' => now()],
        ]);
    }

    public function leave(User $user): void
    {
        $this->members()->detach($user->id);
    }

    /**
     * Post a system message. These carry a null sender — the legacy schema made
     * `sender_id` nullable precisely for join and leave notifications.
     */
    public function systemMessage(string $body): GroupChatMessage
    {
        return $this->chatMessages()->create([
            'sender_id' => null,
            'body' => $body,
            'type' => GroupMessageType::System,
        ]);
    }

    /**
     * Mark every message this user can see in this group as read.
     *
     * Written as one insert rather than a loop of per-message upserts. Opening a group
     * calls this, and a loop would mean a query per message on every room switch - the
     * exact cost that made the legacy read-receipt implementation unusable.
     *
     * The author's own messages are excluded. Somebody has by definition read what they
     * wrote, and counting it would put a permanent unread badge on every group a user has
     * ever posted in.
     *
     * @return int Messages newly marked read.
     */
    public function markAllReadBy(User $user): int
    {
        $unreadIds = $this->chatMessages()
            ->where(fn (Builder $query) => $query
                ->whereNull('sender_id')
                ->orWhere('sender_id', '!=', $user->id))
            ->whereDoesntHave('readBy', fn (Builder $reads) => $reads->whereKey($user->id))
            ->pluck('id');

        if ($unreadIds->isEmpty()) {
            return 0;
        }

        $now = now();

        // insertOrIgnore, not upsert: two browser tabs open on the same group can both
        // decide the same messages are unread, and the second insert would otherwise fail
        // on the (message_id, user_id) unique key and take the whole request down.
        $inserted = DB::table('group_message_reads')->insertOrIgnore(
            $unreadIds->map(fn (int $messageId) => [
                'message_id' => $messageId,
                'user_id' => $user->id,
                'read_at' => $now,
            ])->all()
        );

        return $inserted;
    }

    /**
     * How many messages in this group this user has not read.
     *
     * Null sender_id means a system message, which has no author to have read it.
     *
     * The withExists subquery counts in the same query as the room list rather than
     * per group: the sidebar renders every group at once, so counting each one separately
     * would be one query per row on every page load.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    public function withUnreadCountFor(Builder $query, User $user): void
    {
        $query
            ->withCount([
                'chatMessages as unread_messages_count' => fn (Builder $messages) => $messages
                    ->where(fn (Builder $others) => $others
                        ->whereNull('sender_id')
                        ->orWhere('sender_id', '!=', $user->id))
                    ->whereDoesntHave('readBy', fn (Builder $reads) => $reads->whereKey($user->id)),
            ])
            ->withCount('members');
    }

    /**
     * Unread messages for the user this model was loaded with by withUnreadCountFor().
     *
     * Takes no argument on purpose. The count is decided by the query that fetched the
     * group, so a parameter here could only disagree with it - the same reason
     * unreadBy()/visibleTo() style scopes must not be re-derived at render time.
     */
    public function unreadCountFor(): int
    {
        return (int) ($this->unread_messages_count ?? 0);
    }

    public function latestMessages(int $limit = 50)
    {
        return $this->chatMessages()
            ->with(['sender', 'replyTo'])
            ->latest('created_at')
            ->latest('id')
            ->limit($limit)
            ->get();
    }
}
