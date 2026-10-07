<?php

namespace App\Console\Commands;

use App\Models\Pc;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Report the health of the lab's Lab Client fleet.
 *
 * The question this exists to answer is "which machines are actually reporting",
 * which is otherwise a per-PC guess during enrolment. Machines fall into four
 * groups that each need a different response, so they are listed separately rather
 * than as one count: unenrolled needs an install, not-enrolling needs a look at the
 * token, and silent needs a look at the machine.
 */
class LabClientStatus extends Command
{
    protected $signature = 'pcs:monitoring-status {--json : Machine-readable output}';

    protected $description = 'Report which lab PCs have a working Lab Client';

    public function handle(): int
    {
        $pcs = Pc::query()->with('client')->orderBy('name')->get();

        $unenrolled = $pcs->reject(fn (Pc $pc): bool => $pc->client !== null);
        $enrolled = $pcs->filter(fn (Pc $pc): bool => $pc->client !== null);

        $active = $enrolled->filter(fn (Pc $pc): bool => $pc->client->isActive());
        $revoked = $enrolled->reject(fn (Pc $pc): bool => $pc->client->isActive());
        // "Never checked in" is deliberately its own bucket rather than the most
        // extreme case of "silent".
        //
        // Never-seen means the client is installed but has never reached the server:
        // wrong token, wrong server URL, or a firewall. Silent means it worked and has
        // now stopped. Those send an administrator to different places — the config
        // file versus the machine — so collapsing them into one number would send them
        // to the wrong place half the time.
        $neverSeen = $active->filter(fn (Pc $pc): bool => $pc->client->last_seen_at === null);

        $checkingIn = $active->filter(fn (Pc $pc): bool => $pc->client->last_seen_at !== null);

        $silent = $checkingIn->filter(fn (Pc $pc): bool => $pc->client->isSilent());
        $reporting = $checkingIn->reject(fn (Pc $pc): bool => $pc->client->isSilent());

        if ($this->option('json')) {
            $this->line(json_encode([
                'total' => $pcs->count(),
                'reporting' => $reporting->pluck('name')->values(),
                'silent' => $silent->pluck('name')->values(),
                'never_seen' => $neverSeen->pluck('name')->values(),
                'revoked' => $revoked->pluck('name')->values(),
                'unenrolled' => $unenrolled->pluck('name')->values(),
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            '%d of %d lab PC(s) reporting.',
            $reporting->count(),
            $pcs->count(),
        ));

        $this->list('Reporting', $reporting, fn (Pc $pc): string => sprintf(
            '%s  last frame %s',
            $pc->ip_address,
            $pc->client->last_frame_at?->diffForHumans() ?? 'never',
        ));

        $this->list('Not enrolled', $unenrolled, fn (Pc $pc): string => 'run: pcs:enrol-client "'.$pc->name.'"');

        $this->list('Enrolled, never checked in', $neverSeen, fn (Pc $pc): string => sprintf(
            'host %s — installed but not reaching the server',
            $pc->client->hostname ?? 'unknown',
        ));

        $this->list('Silent', $silent, fn (Pc $pc): string => sprintf(
            'last seen %s',
            $pc->client->last_seen_at?->diffForHumans() ?? 'unknown',
        ));

        $this->list('Revoked', $revoked, fn (Pc $pc): string => 'enrol again to re-enable');

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, Pc>  $pcs
     */
    private function list(string $heading, $pcs, callable $detail): void
    {
        if ($pcs->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->components->twoColumnDetail("<fg=gray>{$heading}</>", '<fg=gray>'.$pcs->count().'</>');

        foreach ($pcs as $pc) {
            $this->components->twoColumnDetail('  '.$pc->name, $detail($pc));
        }
    }
}
