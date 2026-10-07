<?php

namespace App\Console\Commands;

use App\Models\Pc;
use App\Services\PcClientEnrolment;
use Illuminate\Console\Command;

/**
 * Enrol the Lab Client for a lab PC, and print its credential once.
 *
 * The token is shown exactly once and never stored in plaintext — only its SHA-256
 * digest goes in the database. That is the same trade-off Sanctum makes, and it is
 * why this is a command run by an administrator at deployment time rather than
 * something the machine can obtain by itself: legacy's `agent_register` was
 * self-service and accepted by anyone who connected, which let any host on the lab
 * network inject a fake machine into a teacher's grid.
 *
 * Re-running replaces the credential, which is the intended way to rotate one.
 */
class EnrolPcClient extends Command
{
    protected $signature = 'pcs:enrol-client
                            {pc : PC name or id}
                            {--hostname= : Override the reported hostname}
                            {--show-token : Print the token (the default; present for explicitness)}';

    protected $description = 'Enrol the Lab Client for a lab PC and print its token once';

    public function handle(PcClientEnrolment $enrolment): int
    {
        $identifier = (string) $this->argument('pc');

        $pc = Pc::query()
            ->where('name', $identifier)
            ->orWhere('id', is_numeric($identifier) ? (int) $identifier : 0)
            ->first();

        if ($pc === null) {
            $this->components->error("No PC named \"{$identifier}\".");

            return self::FAILURE;
        }

        $hostname = $this->option('hostname');

        // Delegates to PcClientEnrolment, which is the same code path the admin
        // panel's Enrol client button uses. Both mint a fresh token, hash it, and
        // return the plaintext exactly once — there is no second implementation to
        // drift, and no way for one caller to end up storing a live credential.
        $result = $enrolment->enrol(
            $pc,
            $hostname !== null ? (string) $hostname : null,
        );

        $token = $result['token'];

        if ($result['replaced']) {
            $this->components->warn(sprintf(
                'Replaced the existing credential for %s. The previous token no longer works.',
                $pc->name,
            ));
        }

        $this->newLine();
        $this->components->info("Lab Client enrolled for {$pc->name} ({$pc->ip_address}).");
        $this->newLine();
        $this->components->info("Lab Client enrolled for {$pc->name} ({$pc->ip_address}).");
        $this->newLine();

        // Rendered inside a component block so it survives being piped to a file, and
        // spelled out that this is the only time it appears.
        $this->components->twoColumnDetail('<fg=yellow>TOKEN (shown once)</>', "<fg=yellow>{$token}</>");
        $this->newLine();
        $this->line('  Run this on the lab PC:');
        $this->line('    .\\install-client.ps1 -ServerUrl https://YOUR-SERVER -Token '.$token);
        $this->newLine();
        $this->line('  Only a hash of that token is stored. If it is lost, run this command again.');

        return self::SUCCESS;
    }
}
