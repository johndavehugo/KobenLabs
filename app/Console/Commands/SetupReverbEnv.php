<?php

namespace App\Console\Commands;

use App\Services\ReverbEnvSetup;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('app:setup-reverb-env')]
#[Description('Fill blank Reverb variables in .env without touching set ones')]
class SetupReverbEnv extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ReverbEnvSetup $setup): int
    {
        // Secrets are reported as generated, never printed: the credential
        // must not end up in terminal scrollback, CI logs, or screenshots.
        try {
            $result = $setup->fillBlanks();
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($result['filled'] as $key => $value) {
            $this->components->twoColumnDetail($key, '<fg=green>generated</>');
        }

        foreach ($result['kept'] as $key) {
            $this->components->twoColumnDetail($key, 'kept');
        }

        if ($result['filled'] === []) {
            $this->components->info('Reverb environment is complete — nothing to fill.');
        } else {
            $this->components->info('Filled '.count($result['filled']).' blank Reverb variable(s). Run `npm run build` so browsers pick them up.');
        }

        return self::SUCCESS;
    }
}
