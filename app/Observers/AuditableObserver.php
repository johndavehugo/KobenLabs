<?php

namespace App\Observers;

use App\Jobs\RecordAuditEntry;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Records who changed what, in `audit_logs`.
 *
 * Legacy created the table and then never wrote to it, so there was no record of
 * a promoted user, a deleted drive, or a schedule quietly moved. This is security
 * must-fix item 24.
 *
 * Two decisions worth stating, because both trade completeness for usefulness:
 *
 * - **Only changes, not reads.** The table records create/update/delete. Polling
 *   every read would make the log useless.
 * - **A configured model list, not everything.** Attendance rows, PC sessions and
 *   chat messages would bury the entries that matter. See `config('lab.audit.models')`.
 *
 * Writes go through the queue so auditing never blocks a request. That means the
 * entry appears slightly after the change, which is the right trade: an audit
 * failure should not roll back the user's action.
 */
class AuditableObserver
{
    public function created(Model $model): void
    {
        $this->record('created', $model, [
            'attributes' => $this->redact($model->getAttributes()),
        ]);
    }

    /**
     * Only the attributes that actually changed, as from/to pairs.
     *
     * `getChanges()` alone returns current values only, which answers "what does it
     * look like now" but not "what happened". `getOriginal()` still holds the
     * pre-save values while the `updated` event is running, so both are available.
     *
     * Logging the whole row on every save would put a full copy of every user and
     * every schedule into the trail on each batch Filament sends.
     */
    public function updated(Model $model): void
    {
        $changes = $model->getChanges();

        if ($changes === []) {
            return;
        }

        $original = $model->getOriginal();
        $redact = (array) config('lab.audit.redact', []);

        $fromTo = [];

        foreach ($changes as $key => $new) {
            $fromTo[$key] = [
                'from' => in_array($key, $redact, true) ? '[redacted]' : ($original[$key] ?? null),
                'to' => $this->redactValue($key, $new),
            ];
        }

        $this->record('updated', $model, ['changes' => $fromTo]);
    }

    public function deleted(Model $model): void
    {
        // Captured here rather than after the delete, because by the time a
        // deleting observer returns the attributes are already gone from the
        // query builder's point of view.
        //
        // No soft-delete branch: nothing in this schema has a deleted_at column,
        // so `isForceDeleting()` does not exist on these models and asking for it
        // would be a fatal error rather than a false.
        $this->record('deleted', $model, [
            'attributes' => $this->redact($model->getOriginal()),
        ]);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function record(string $action, Model $model, array $properties): void
    {
        // Never audit the audit log, or every write logs another write.
        if ($model instanceof AuditLog) {
            return;
        }

        // Dispatched as a built instance rather than through the job's constructor
        // arguments, because the request metadata is captured here, while the
        // request that caused the change still exists.
        dispatch(RecordAuditEntry::forModel($action.'.'.class_basename($model), $model, $properties));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function redact(array $attributes): array
    {
        $redact = (array) config('lab.audit.redact', []);

        foreach ($redact as $key) {
            if (array_key_exists($key, $attributes)) {
                $attributes[$key] = '[redacted]';
            }
        }

        return $attributes;
    }

    private function redactValue(string $key, mixed $value): mixed
    {
        return in_array($key, (array) config('lab.audit.redact', []), true)
            ? '[redacted]'
            : $value;
    }
}
