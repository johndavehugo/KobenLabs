<?php

namespace App\Listeners;

use App\Services\PcPresenceService;
use Illuminate\Auth\Events\Login;

/**
 * Detects which lab machine a user signed in from and opens a PC session.
 */
class RecordPcPresenceOnLogin
{
    public function __construct(private readonly PcPresenceService $presence) {}

    public function handle(Login $event): void
    {
        $this->presence->recordLogin(
            $event->user,
            request()?->ip(),
        );
    }
}
