<?php

namespace App\Console\Commands;

use App\Enums\PcStatus;
use App\Models\Pc;
use App\Services\PcPresenceService;
use Illuminate\Console\Command;

class MarkStalePcsOffline extends Command
{
    protected $signature = 'pcs:mark-stale-offline
                            {--threshold= : Override the stale window in minutes}
                            {--dry-run : Report what would change without writing}';

    protected $description = 'Mark lab PCs offline when they stop checking in';

    /**
     * The legacy app ran this from a GET endpoint that the PC view called on
     * every page load, so machines were only ever collected when somebody
     * happened to open that screen. Scheduling it means collection no longer
     * depends on somebody looking.
     */
    public function handle(PcPresenceService $presence): int
    {
        $threshold = $this->option('threshold') !== null
            ? (int) $this->option('threshold')
            : null;

        if ($this->option('dry-run')) {
            $window = $threshold ?? (int) config('lab.pcs.stale_after_minutes');

            $candidates = Pc::query()
                ->where('status', PcStatus::Online)
                ->get()
                ->filter(fn (Pc $pc) => $pc->isStale($window));

            $this->components->info(sprintf(
                '%d PC(s) would be marked offline after %d minute(s) of silence.',
                $candidates->count(),
                $window,
            ));

            foreach ($candidates as $pc) {
                $this->components->twoColumnDetail($pc->name, $pc->ip_address);
            }

            return self::SUCCESS;
        }

        $count = $presence->markStalePcsOffline($threshold);

        $this->components->info($count === 1
            ? '1 PC marked offline.'
            : "{$count} PCs marked offline.");

        return self::SUCCESS;
    }
}
