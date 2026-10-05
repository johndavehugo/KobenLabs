<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Audit trail.
 *
 * This table existed in the legacy schema but was never read or written — the
 * application logged audit entries as JSON lines to `logs/audit.log` instead.
 * The shape was right; it just needed wiring.
 */
#[Fillable([
    'user_id',
    'action',
    'auditable_type',
    'auditable_id',
    'properties',
    'ip_address',
    'user_agent',
    'created_at',
])]
class AuditLog extends Model
{
    /**
     * Append-only: an audit row records something that already happened.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    #[Scope]
    public function forAction(Builder $query, string $action): void
    {
        $query->where('action', $action);
    }

    #[Scope]
    public function forModel(Builder $query, Model $model): void
    {
        $query->where('auditable_type', $model::class)
            ->where('auditable_id', $model->getKey());
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public static function record(string $action, ?Model $subject = null, array $properties = [], ?int $userId = null): self
    {
        return static::create([
            'user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'auditable_type' => $subject !== null ? $subject::class : null,
            'auditable_id' => $subject?->getKey(),
            'properties' => $properties ?: null,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'created_at' => now(),
        ]);
    }
}
