<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Support\Bytes;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

#[Fillable([
    'username',
    'email',
    'password',
    'first_name',
    'last_name',
    'student_id',
    'year_section_id',
    'role',
    'status',
    'storage_quota_mb',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasName
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'status' => UserStatus::class,
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'storage_used_mb' => 'decimal:2',
        ];
    }

    // -----------------------------------------------------------------
    // Relationships
    // -----------------------------------------------------------------

    public function yearSection(): BelongsTo
    {
        return $this->belongsTo(YearSection::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(StoredFile::class);
    }

    /** Root-level folders in this user's drive. */
    public function rootFolders(): HasMany
    {
        return $this->hasMany(StoredFile::class)->whereNull('parent_id');
    }

    /** Schedules this user teaches. */
    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class, 'teacher_id');
    }

    public function classSessions(): HasMany
    {
        return $this->hasMany(ClassSession::class, 'started_by_id');
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function attendedSessions(): BelongsToMany
    {
        return $this->belongsToMany(ClassSession::class, 'attendances')
            ->withPivot('joined_at')
            ->withTimestamps();
    }

    public function pcSessions(): HasMany
    {
        return $this->hasMany(PcSession::class);
    }

    /** PC sessions this user force-ended as an administrator. */
    public function terminatedPcSessions(): HasMany
    {
        return $this->hasMany(PcSession::class, 'terminated_by_id');
    }

    /**
     * Group chats this user created.
     *
     * The only kind of conversation that exists. Direct messaging was removed: a teacher
     * sharing a screenshot or a block of code with a class mid-lesson needs a room that
     * everybody can see, not thirty inboxes to open one at a time - and one-to-one
     * teacher-to-student threads were the thing that made a lesson feel like surveillance
     * rather than support.
     */
    public function createdGroupChats(): HasMany
    {
        return $this->hasMany(GroupChat::class, 'created_by_id');
    }

    public function groupChats(): BelongsToMany
    {
        return $this->belongsToMany(GroupChat::class, 'group_chat_members')
            ->withPivot(['role', 'joined_at']);
    }

    public function accessibilitySettings(): HasOne
    {
        return $this->hasOne(AccessibilitySetting::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    #[Scope]
    public function active(Builder $query): void
    {
        $query->where('status', UserStatus::Active);
    }

    #[Scope]
    public function ofRole(Builder $query, UserRole $role): void
    {
        $query->where('role', $role);
    }

    #[Scope]
    public function students(Builder $query): void
    {
        $query->where('role', UserRole::Student);
    }

    #[Scope]
    public function staff(Builder $query): void
    {
        $query->whereIn('role', [UserRole::Teacher, UserRole::Admin]);
    }

    /**
     * `full_name` for display. Defined as an accessor rather than a plain method
     * so that `TextColumn::make('full_name')` and Filament's
     * `$recordTitleAttribute` can resolve it — Eloquent would otherwise treat a
     * bare `fullName()` method as a relationship and throw.
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => trim("{$this->first_name} {$this->last_name}"));
    }

    protected function isFullQuota(): Attribute
    {
        return Attribute::get(fn (): bool => $this->storageQuotaBytes() > 0
            && $this->storageUsedBytes() >= $this->storageQuotaBytes());
    }

    /**
     * Active staff as an id => name map, for select fields and filters.
     *
     * Provided here because `full_name` is an accessor, so Filament's
     * `->relationship('teacher', 'full_name')` cannot resolve it — that tries to
     * select and order by `users.full_name` in SQL, which does not exist.
     *
     * @return Collection<int, string>
     */
    public static function staffOptions(): Collection
    {
        return static::query()
            ->staff()
            ->active()
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->mapWithKeys(fn (self $user) => [$user->id => $user->full_name]);
    }

    // -----------------------------------------------------------------
    // Behaviour
    // -----------------------------------------------------------------

    /**
     * Teachers and administrators share every staff capability. Replaces the
     * `requireTeacherRole()` helper that was copy-pasted across six legacy
     * controllers.
     */
    public function canManageLab(): bool
    {
        return $this->role->canManageLab();
    }

    public function canAuthenticate(): bool
    {
        return $this->status->canAuthenticate();
    }

    /**
     * Panel access.
     *
     * Filament requires this contract in production: without it, no one can
     * sign in when APP_ENV is not `local`. With it, each panel can be gated.
     *
     * Replaces the legacy pair of client-side redirects — `admin-portal.js`
     * bounced non-staff to the student portal and `user-portal.js` bounced
     * non-students the other way, both after the page had already rendered.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->canAuthenticate()) {
            return false;
        }

        return match ($panel->getId()) {
            'admin' => $this->canManageLab(),
            'student' => $this->isStudent(),
            default => false,
        };
    }

    /**
     * How Filament renders this account's name.
     *
     * Required because the migration dropped the single `name` column in favour
     * of `first_name` + `last_name` and a `full_name` accessor. Without this
     * contract, FilamentManager::getUserName() falls back to reading a literal
     * `name` attribute, gets null, and throws a TypeError — which took the whole
     * dashboard down, since the AccountWidget renders on it.
     */
    public function getFilamentName(): string
    {
        // Both name columns are NOT NULL but may hold an empty string, and the
        // return type has to stay `string`.
        return $this->full_name !== '' ? $this->full_name : $this->username;
    }

    public function isStudent(): bool
    {
        return $this->role === UserRole::Student;
    }

    public function markLogin(): void
    {
        $this->forceFill(['last_login_at' => now()])->saveQuietly();
    }

    // -----------------------------------------------------------------
    // Storage quota
    // -----------------------------------------------------------------

    public function storageQuotaBytes(): int
    {
        return ((int) $this->storage_quota_mb) * 1024 * 1024;
    }

    public function storageUsedBytes(): int
    {
        return (int) round(((float) $this->storage_used_mb) * 1024 * 1024);
    }

    public function storageRemainingBytes(): int
    {
        return max(0, $this->storageQuotaBytes() - $this->storageUsedBytes());
    }

    /**
     * Recompute the denormalised counter from the file tree. The legacy counter
     * was only ever incremented on user file upload and never for message or
     * group chat attachments, so it drifts.
     */
    public function recalculateStorageUsed(): void
    {
        $bytes = (int) $this->files()->where('is_folder', false)->sum('size');

        $this->forceFill([
            'storage_used_mb' => round($bytes / 1024 / 1024, 2),
        ])->saveQuietly();
    }

    protected function humanStorageUsed(): Attribute
    {
        return Attribute::get(fn (): string => Bytes::format($this->storageUsedBytes()));
    }

    /**
     * A zero quota means unlimited, so report that rather than "0 MB".
     */
    protected function humanStorageQuota(): Attribute
    {
        return Attribute::get(function (): string {
            $quota = $this->storageQuotaBytes();

            return $quota > 0 ? Bytes::format($quota) : 'Unlimited';
        });
    }
}
