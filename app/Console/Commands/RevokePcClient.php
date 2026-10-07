<?php

namespace App\Console\Commands;

use App\Models\Pc;
use App\Services\PcClientEnrolment;
use Illuminate\Console\Command;

/**
 * Revoke a Lab Client's credential and discard its last frame.
 *
 * Revocation is a state change rather than a delete, so the machine's enrolment
 * history survives and the old token stays permanently dead. The stored frame goes
 * too: a decommissioned machine's last screenshot has no reason to remain readable
 * after the credential that produced it has been withdrawn.
 */
class RevokePcClient extends Command
{
    protected $signature = 'pcs:revoke-client
                            {pc? : PC name or id}
                            {--all : Revoke every enrolled client}';

    protected $description = "Revoke a lab PC's Lab Client credential";

    public function handle(PcClientEnrolment $enrolment): int
    {
        if ($this->option('all')) {
            $count = $enrolment->revokeAll();

            $this->components->info($count === 0
                ? 'No active Lab Clients to revoke.'
                : sprintf('Revoked %d Lab Client(s) and deleted their stored frames.', $count));

            return self::SUCCESS;
        }

        $identifier = $this->argument('pc');

        if ($identifier === null) {
            $this->components->error('Give a PC name, or pass --all.');

            return self::FAILURE;
        }

        $pc = Pc::query()
            ->where('name', $identifier)
            ->orWhere('id', is_numeric($identifier) ? (int) $identifier : 0)
            ->first();

        if ($pc === null) {
            $this->components->error("No PC named \"{$identifier}\".");

            return self::FAILURE;
        }

        $client = $pc->client;

        if ($client === null) {
            $this->components->warn("{$pc->name} has no Lab Client enrolled.");

            return self::SUCCESS;
        }

        $enrolment->revoke($pc);

        $this->components->info("Revoked the Lab Client for {$pc->name} and deleted its last frame.");

        return self::SUCCESS;
    }
}
