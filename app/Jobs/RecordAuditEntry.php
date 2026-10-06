<?php

namespace App\Jobs;

use App\Models\AuditLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;

/**
 * Write one audit entry.
 *
 * Queued so that logging never blocks the request that caused the change, which is
 * what `config('lab.audit.queue')` has always claimed.
 *
 * The user id and request metadata are captured *before* queueing. A queued job
 * runs outside the original request, so by the time it executes there may be no
 * authenticated user and no request to read an IP from — the entry would silently
 * lose the only information that makes an audit trail worth having.
 *
 * Trade-off: if the queue is down the entry is lost. That is deliberate. An audit
 * failure must not roll back the staff action that caused it.
 */
class RecordAuditEntry implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $properties
     */
    public function __construct(
        public readonly string $action,
        public readonly ?string $auditableType,
        public readonly int|string|null $auditableId,
        public readonly array $properties,
        public readonly ?int $userId,
        public readonly ?string $ipAddress,
        public readonly ?string $userAgent,
    ) {
        $this->onQueue(config('lab.audit.queue'));
    }

    /**
     * Build a job from the model, capturing request state while it still exists.
     *
     * @param  array<string, mixed>  $properties
     */
    public static function forModel(string $action, Model $model, array $properties = []): self
    {
        return new self(
            action: $action,
            auditableType: $model::class,
            auditableId: $model->getKey(),
            properties: $properties,
            userId: Auth::id(),
            ipAddress: request()?->ip(),
            userAgent: request()?->userAgent(),
        );
    }

    public function handle(): void
    {
        AuditLog::create([
            'user_id' => $this->userId,
            'action' => $this->action,
            'auditable_type' => $this->auditableType,
            'auditable_id' => $this->auditableId,
            'properties' => $this->properties ?: null,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'created_at' => now(),
        ]);
    }
}
