<?php

namespace App\Models;

use App\Enums\PcSessionStatus;
use Database\Factories\PcSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's occupancy of a lab PC.
 *
 * Legacy name: `sessions`. Renamed to `pc_sessions` because Laravel's own DB
 * session driver already occupies the `sessions` table name.
 */
#[Fillable([
    'user_id',
    'pc_id',
    'started_at',
    'ended_at',
    'duration_minutes',
    'status',
    'terminated_by_id',
    'termination_reason',
])]
class PcSession extends Model
{
    /** @use HasFactory<PcSessionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PcSessionStatus::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pc(): BelongsTo
    {
        return $this->belongsTo(Pc::class);
    }

    public function terminatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'terminated_by_id');
    }

    #[Scope]
    public function active(Builder $query): void
    {
        $query->where('status', PcSessionStatus::Active);
    }

    #[Scope]
    public function between(Builder $query, string $from, string $to): void
    {
        $query->whereBetween('started_at', [$from, $to]);
    }

    public function isActive(): bool
    {
        return $this->status === PcSessionStatus::Active;
    }

    /**
     * Close the session and derive its duration. Terminates an already-closed
     * session is a no-op, which makes the call idempotent.
     */
    public function close(PcSessionStatus $status = PcSessionStatus::Completed, ?int $terminatedBy = null, ?string $reason = null): void
    {
        if (! $this->isActive()) {
            return;
        }

        $endedAt = now();
        $startedAt = $this->started_at ?? $endedAt;

        $this->forceFill([
            'status' => $status,
            'ended_at' => $endedAt,
            'duration_minutes' => (int) round($startedAt->diffInMinutes($endedAt)),
            'terminated_by_id' => $terminatedBy,
            'termination_reason' => $reason,
        ])->save();
    }
}
