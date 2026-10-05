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
