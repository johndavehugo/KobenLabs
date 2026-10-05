<?php

namespace App\Services;

use App\Enums\PcSessionStatus;
use App\Enums\PcStatus;
use App\Events\PcStatusUpdated;
use App\Models\Pc;
use App\Models\PcSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Lab PC presence: which machine a user is sitting at, and when it went idle.
 *
 * Replaces the legacy auto-detect logic, which lived inside
 * `AuthController::login()` and compared `$_SERVER['REMOTE_ADDR']` against
 * `pcs.ip_address` inline, then wrote a row into the `sessions` table. That
 * table is now `pc_sessions`, because Laravel's own DB session driver already
 * owns the `sessions` name.
 *
 * Invariants carried over from the legacy app:
 *   - a PC is identified by its IP address, never its MAC
 *   - loopback logins are ignored, so working on the server host does not bring
 *     a lab machine online
 *   - logging out clears `last_seen` as well as flipping the status
 */
class PcPresenceService
{
    /**
     * Open a session for the machine this login came from.
     *
     * Returns the PC that was detected, or null when the login did not come
     * from a registered lab machine.
     */
    public function recordLogin(User $user, ?string $ipAddress): ?Pc
    {
        $pc = Pc::detectFromRequest($ipAddress);

        if ($pc === null) {
            return null;
        }

        DB::transaction(function () use ($pc, $user): void {
            // The machine is now occupied, so any previous occupancy of it is
            // over. Without this a PC left online by a crashed session would
            // accumulate overlapping rows.
            PcSession::query()
                ->where('pc_id', $pc->id)
                ->active()
                ->get()
                ->each(fn (PcSession $session) => $session->close(PcSessionStatus::Completed));

            // Likewise, one user cannot occupy two machines at once.
            PcSession::query()
                ->where('user_id', $user->id)
                ->active()
                ->get()
                ->each(fn (PcSession $session) => $session->close(PcSessionStatus::Completed));

            PcSession::query()->create([
                'user_id' => $user->id,
                'pc_id' => $pc->id,
                'started_at' => now(),
                'status' => PcSessionStatus::Active,
            ]);

            $pc->markOnline();
        });

        PcStatusUpdated::dispatch($pc->id, $pc->name, PcStatus::Online);

        return $pc;
    }

    /**
     * Close whatever session this user had open and release the machine.
     */
    public function recordLogout(User $user): void
    {
        $sessions = PcSession::query()
            ->where('user_id', $user->id)
            ->active()
            ->get();

        if ($sessions->isEmpty()) {
            return;
        }

        $pcIds = $sessions->pluck('pc_id')->unique();

        DB::transaction(function () use ($sessions): void {
            $sessions->each(fn (PcSession $session) => $session->close(PcSessionStatus::Completed));
        });

        // Only release a machine when nothing else has claimed it in the
        // meantime; a second user logging into the same PC since would otherwise
        // be logged out of it by this event.
        $released = Pc::query()
            ->whereIn('id', $pcIds)
            ->whereDoesntHave('activeSessions')
            ->get();

        foreach ($released as $pc) {
            $pc->markOffline();
            PcStatusUpdated::dispatch($pc->id, $pc->name, PcStatus::Offline);
        }
    }

    /**
     * Refresh `last_seen` for the machine a user is currently on.
     *
     * Replaces the legacy `POST /api/heartbeat` endpoint, which every open panel
     * called every five minutes purely to keep the session alive and stamp the
     * machine.
     */
    public function recordHeartbeat(User $user): void
    {
        Pc::query()
            ->whereIn('id', PcSession::query()
                ->where('user_id', $user->id)
                ->active()
                ->select('pc_id'),
            )
            ->get()
            ->each(fn (Pc $pc) => $pc->touchLastSeen());
    }

    /**
     * Collect machines that stopped checking in.
     *
     * The legacy app ran this from `GET /api/pcs/cleanup-stale`, which the PC
     * view called on every page load — so the sweep happened whenever somebody
     * happened to open the page, and at no other time.
     *
     * @return int Number of machines released.
     */
    public function markStalePcsOffline(?int $thresholdMinutes = null): int
    {
        $threshold = $thresholdMinutes ?? (int) config('lab.pcs.stale_after_minutes');

        $stale = Pc::query()
            ->where('status', PcStatus::Online)
            ->where(function ($query) use ($threshold): void {
                $query->whereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<', now()->subMinutes($threshold));
            })
            // Maintenance machines are exempt, so an administrator taking a PC
            // out of service does not have to keep logging in to hold the
            // status. Encoded on PcStatus::isAutoOfflinable().
            ->get()
            ->filter(fn (Pc $pc) => $pc->isStale($threshold));

        foreach ($stale as $pc) {
            $pc->markOffline();
            PcStatusUpdated::dispatch($pc->id, $pc->name, PcStatus::Offline);
        }

        return $stale->count();
    }
}
