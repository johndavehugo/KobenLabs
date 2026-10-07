<?php

namespace App\Services;

use App\Enums\PcClientState;
use App\Models\Pc;
use App\Models\PcClient;

/**
 * Issuing and withdrawing a Lab Client credential.
 *
 * This logic was previously duplicated in the `pcs:enrol-client` and `pcs:revoke-client`
 * commands. That was how `pcs:enrol-client` came to store the live credential in
 * `token_hash` instead of a digest of it: the bug lived in a command no other caller
 * shared, so nothing else in the suite exercised it and no second path could inherit
 * the fix by accident.
 *
 * One implementation, reached from both the artisan commands (fleet scripts, GPO) and
 * the Filament buttons (what a human uses). Whichever runs, the code is hashed the
 * same way.
 *
 * Identity note: a credential is bound to a `pcs` row by primary key, never by IP. A
 * lab PC that gets a new DHCP lease keeps working; only attendance depends on IP.
 */
class PcClientEnrolment
{
    public function __construct(private readonly ScreenFrameService $frames) {}

    /**
     * Issue a credential for a PC, replacing any previous one.
     *
     * The plaintext is returned and never stored. It exists for exactly as long as it
     * takes to show it to an administrator, and only its SHA-256 digest reaches the
     * database — so a dump, a backup, or an export of `audit_logs` cannot be replayed
     * as a lab machine.
     *
     * Minting happens before the first insert rather than after: `token_hash` is NOT
     * NULL, so save-then-hash is a constraint violation on a brand new row.
     *
     * @return array{client: PcClient, token: string, replaced: bool}
     */
    public function enrol(Pc $pc, ?string $hostname = null): array
    {
        $token = PcClient::generateToken();
        $hash = PcClient::hashToken($token);

        $existing = $pc->client;
        $replaced = $existing !== null;

        if ($existing !== null) {
            $client = $existing;
            $client->forceFill(['token_hash' => $hash]);
        } else {
            $client = new PcClient(['pc_id' => $pc->getKey(), 'token_hash' => $hash]);
        }

        $client->forceFill([
            'state' => PcClientState::Active,
            'enrolled_at' => now(),
            'revoked_at' => null,
        ]);

        if ($hostname !== null && $hostname !== '') {
            $client->hostname = $hostname;
        }

        $client->save();

        return ['client' => $client, 'token' => $token, 'replaced' => $replaced];
    }

    /**
     * Withdraw a credential and delete the frame it produced.
     *
     * Revoked rather than deleted: a decommissioned machine should leave evidence
     * that it existed, and its token hash stays on file so it can never be replayed.
     * The stored frame goes too — a machine nobody may watch should not have a
     * screenshot of its last screen sitting on disk.
     */
    public function revoke(Pc $pc): bool
    {
        $client = $pc->client;

        if ($client === null) {
            return false;
        }

        $client->revoke();

        $this->frames->deleteForPc((int) $pc->getKey());

        return true;
    }

    /**
     * Revoke every active credential. Returns how many were affected.
     */
    public function revokeAll(): int
    {
        $clients = PcClient::query()->with('pc')->active()->get();

        foreach ($clients as $client) {
            $client->revoke();
            $this->frames->deleteForPc((int) $client->pc_id);
        }

        return $clients->count();
    }
}
