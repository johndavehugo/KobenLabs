<?php

namespace App\Models;

use App\Enums\ScheduleStatus;
use App\Observers\ScheduleObserver;
use Database\Factories\ScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'teacher_id',
    'year_section_id',
    'title',
    'description',
    'starts_at',
    'ends_at',
    'purpose',
    'status',
])]
#[ObservedBy(ScheduleObserver::class)]
class Schedule extends Model
{
    /** @use HasFactory<ScheduleFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ScheduleStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function yearSection(): BelongsTo
    {
        return $this->belongsTo(YearSection::class);
    }

    public function classSessions(): HasMany
    {
        return $this->hasMany(ClassSession::class);
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    #[Scope]
    public function ofStatus(Builder $query, ScheduleStatus $status): void
    {
        $query->where('status', $status);
    }

    /** Bookable schedules only — cancelled entries never block or appear. */
    #[Scope]
    public function bookable(Builder $query): void
    {
        $query->where('status', '!=', ScheduleStatus::Cancelled);
    }

    #[Scope]
    public function between(Builder $query, string $from, string $to): void
    {
        $query->where('starts_at', '<=', $to)->where('ends_at', '>=', $from);
    }

    // -----------------------------------------------------------------
    // Derived values
    // -----------------------------------------------------------------

    /**
     * Derived rather than stored. The legacy controller returned these as
     * ad-hoc view-model fields on the API response.
     */
    protected function dayOfWeek(): Attribute
    {
        return Attribute::get(fn (): string => $this->starts_at->format('l'));
    }

    protected function startTime(): Attribute
    {
        return Attribute::get(fn (): string => $this->starts_at->format('H:i'));
    }

    protected function endTime(): Attribute
    {
        return Attribute::get(fn (): string => $this->ends_at->format('H:i'));
    }

    protected function durationMinutes(): Attribute
    {
        return Attribute::get(fn (): int => (int) $this->starts_at->diffInMinutes($this->ends_at));
    }

    protected function isValidDuration(): Attribute
    {
        return Attribute::get(fn (): bool => $this->duration_minutes >= config('lab.schedules.min_duration_minutes')
            && $this->duration_minutes <= config('lab.schedules.max_duration_minutes'));
    }

    // -----------------------------------------------------------------
    // Behaviour
    // -----------------------------------------------------------------

    public function overlaps(self $other): bool
    {
        return $this->starts_at->lt($other->ends_at)
            && $this->ends_at->gt($other->starts_at);
    }

    /**
     * Schedules that clash with this one. Mirrors the legacy overlap query
     * (`start < :end AND end > :start AND status != 'cancelled'`), which is
     * half-open so back-to-back bookings do not conflict.
     *
     * The schedule itself is always excluded, so this can be used to validate an
     * existing record as well as a prospective one.
     *
     * Scoped to the same teacher and the same section — the legacy implementation
     * checked globally, which was almost certainly too broad.
     *
     * @return Collection<int, self>
     */
    public function conflictsWith(?int $excludeId = null): Collection
    {
        return static::query()
            ->bookable()
            ->whereKeyNot($excludeId ?? $this->getKey())
            ->where('teacher_id', $this->teacher_id)
            ->when(
                $this->year_section_id !== null,
                fn (Builder $q) => $q->where('year_section_id', $this->year_section_id),
                fn (Builder $q) => $q->whereNull('year_section_id'),
            )
            ->where('starts_at', '<', $this->ends_at)
            ->where('ends_at', '>', $this->starts_at)
            ->get();
    }
}
