<?php

namespace App\Listeners;

use App\Services\PcPresenceService;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Releases the lab machine a user was occupying when they signed out.
 *
 * Also stamps `last_login_at`, which the legacy app did inside
 * `AuthService::createSession()` — meaning a session's recorded login time came
 * from the previous session rather than the current one.
 */
class RecordPcPresenceOnLogout
{
    public function __construct(private readonly PcPresenceService $presence) {}

    public function handle(Logout $event): void
    {
        $user = $event->user;

        if (! $user instanceof Authenticatable) {
            return;
        }

        if (method_exists($user, 'markLogin')) {
            $user->markLogin();
        }

        $this->presence->recordLogout($user);
    }
}
