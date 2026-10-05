<?php

namespace App\Models;

use App\Enums\ClassSessionStatus;
use App\Observers\ClassSessionObserver;
use Database\Factories\ClassSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['schedule_id', 'started_by_id', 'started_at', 'ended_at', 'status'])]
#[ObservedBy(ClassSessionObserver::class)]
class ClassSession extends Model
{
    /** @use HasFactory<ClassSessionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ClassSessionStatus::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_id');
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function attendees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'attendances')
            ->withPivot('joined_at')
            ->withTimestamps();
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    #[Scope]
    public function active(Builder $query): void
    {
        $query->where('status', ClassSessionStatus::Active);
    }

    #[Scope]
    public function completed(Builder $query): void
    {
        $query->where('status', ClassSessionStatus::Completed);
    }

    // -----------------------------------------------------------------
    // Behaviour
    // -----------------------------------------------------------------

    public function isActive(): bool
    {
        return $this->status === ClassSessionStatus::Active;
    }

    public function end(): void
    {
        if (! $this->isActive()) {
            return;
        }

        $this->forceFill([
            'status' => ClassSessionStatus::Completed,
            'ended_at' => now(),
        ])->save();
    }

    /**
     * Record attendance. Idempotent, backed by the unique index on
     * (class_session_id, user_id) — the legacy app relied on INSERT IGNORE to
     * swallow the duplicate.
     *
     * @return bool True when a new record was created, false when already present.
     */
    public function recordAttendance(User $user): bool
    {
        return Attendance::query()->firstOrCreate(
            ['class_session_id' => $this->id, 'user_id' => $user->id],
        )->wasRecentlyCreated;
    }

    /**
     * PCs currently occupied by this session's attendees.
     */
    public function occupiedPcs()
    {
        return Pc::query()
            ->whereIn(
                'id',
                PcSession::query()
                    ->whereIn('user_id', $this->attendance()->select('user_id'))
                    ->active()
                    ->select('pc_id'),
            )
            ->get();
    }
}
