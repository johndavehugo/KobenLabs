<?php

namespace App\Observers;

use App\Enums\ClassSessionStatus;
use App\Models\ClassSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Enforces the one-active-class-session rule.
 *
 * MySQL has no partial unique index, so this cannot be a constraint. The legacy
 * app enforced it with an `if (already active) return 409` check in the
 * controller, which meant two concurrent requests could both pass the check and
 * both insert.
 *
 * A cache lock around the read-then-write closes that window. The `array` cache
 * store is per-process and would not serialise across workers, but it is only
 * used by the in-memory test database — production uses the `database` store,
 * which is shared.
 */
class ClassSessionObserver
{
    /**
     * Default lock lifetime in seconds. Long enough to cover the transaction,
     * short enough that a crashed process cannot wedge the lab shut.
     */
    private const LOCK_SECONDS = 10;

    public function creating(ClassSession $session): void
    {
        if (! config('lab.class_sessions.single_active_session')) {
            return;
        }

        // The invariant is "at most one *active* session". Creating an already
        // completed one must stay possible, or historical data could never be
        // imported.
        if ($session->status !== ClassSessionStatus::Active) {
            return;
        }

        $lock = Cache::lock('class-sessions:single-active', self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'schedule_id' => 'Another class is already running. End it before starting another.',
            ]);
        }

        try {
            $alreadyActive = ClassSession::query()
                ->active()
                ->lockForUpdate()
                ->exists();

            if ($alreadyActive) {
                throw ValidationException::withMessages([
                    'schedule_id' => 'Another class is already running. End it before starting another.',
                ]);
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * A completed session may still be re-opened by hand, so closing one is
     * always allowed. Nothing to enforce here — recorded because the asymmetry
     * is deliberate.
     */
    public function updating(ClassSession $session): void
    {
        if ($session->isDirty('status') && $session->status !== ClassSessionStatus::Active) {
            $session->ended_at ??= now();
        }
    }
}
