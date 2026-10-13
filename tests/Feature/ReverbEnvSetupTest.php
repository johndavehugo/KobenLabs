<?php

use App\Filament\Widgets\ReverbHealth;
use App\Models\User;
use App\Services\ReverbEnvSetup;

/**
 * The blank-Reverb-keys story.
 *
 * Fresh clones ship every REVERB_* key present but empty, and vendor
 * `reverb:install` only appends missing lines — so it silently writes nothing.
 * ReverbEnvSetup fills the blanks instead and never touches set values.
 */
function reverbTempEnv(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'reverb-env-setup-');

    if ($path === false) {
        throw new RuntimeException('Could not create a temporary env file.');
    }

    file_put_contents($path, $contents);

    return $path;
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/reverb-env-setup-*') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
});

describe('ReverbEnvSetup service', function (): void {
    it('fills blank keys and leaves set values alone', function (): void {
        $path = reverbTempEnv(implode("\n", [
            'APP_NAME=KobenLabs',
            '# REVERB_APP_KEY=commented-out-is-not-the-variable',
            'REVERB_APP_ID=',
            'REVERB_APP_KEY=',
            'REVERB_APP_SECRET=',
            'REVERB_HOST=',
            'REVERB_PORT=',
            'REVERB_SCHEME=',
            'DB_DATABASE=koben_lab',
            '',
        ]));

        $result = (new ReverbEnvSetup($path))->fillBlanks();

        expect(array_keys($result['filled']))->toBe([
            'REVERB_APP_ID', 'REVERB_APP_KEY', 'REVERB_APP_SECRET',
            'REVERB_HOST', 'REVERB_PORT', 'REVERB_SCHEME',
        ])->and($result['kept'])->toBe([]);

        $contents = file_get_contents($path);

        expect($contents)->toContain('DB_DATABASE=koben_lab')
            ->toContain('# REVERB_APP_KEY=commented-out-is-not-the-variable')
            ->not->toContain('REVERB_APP_ID='."\n")
            ->toMatch('/^REVERB_APP_ID=\d{6}$/m')
            ->toMatch('/^REVERB_APP_KEY=[a-z0-9]{20}$/m')
            ->toMatch('/^REVERB_APP_SECRET=[a-z0-9]{20}$/m')
            ->toContain('REVERB_HOST="localhost"')
            ->toContain('REVERB_PORT=8080')
            ->toContain('REVERB_SCHEME=http');
    });

    it('appends keys that are missing entirely', function (): void {
        $path = reverbTempEnv("APP_NAME=KobenLabs\nDB_DATABASE=koben_lab");

        $result = (new ReverbEnvSetup($path))->fillBlanks();

        expect($result['filled'])->toHaveCount(6);

        $contents = file_get_contents($path);

        expect($contents)->toContain("DB_DATABASE=koben_lab\nREVERB_APP_ID=")
            ->toMatch('/^REVERB_SCHEME=http$/m');
    });

    it('treats quoted-empty as blank but keeps quoted values byte-identical', function (): void {
        $path = reverbTempEnv(implode("\n", [
            'REVERB_APP_KEY=""',
            "REVERB_APP_SECRET=''",
            'REVERB_HOST="192.168.1.10"',
            'REVERB_PORT=8080',
            '',
        ]));

        $result = (new ReverbEnvSetup($path))->fillBlanks();

        expect(array_keys($result['filled']))->toBe([
            'REVERB_APP_ID', 'REVERB_APP_KEY', 'REVERB_APP_SECRET', 'REVERB_SCHEME',
        ])->and($result['kept'])->toBe(['REVERB_HOST', 'REVERB_PORT']);

        expect(file_get_contents($path))->toContain('REVERB_HOST="192.168.1.10"');
    });

    it('is a no-op on a second run', function (): void {
        $path = reverbTempEnv("REVERB_APP_KEY=\nREVERB_HOST=\n");

        $first = (new ReverbEnvSetup($path))->fillBlanks();
        $contents = file_get_contents($path);
        $second = (new ReverbEnvSetup($path))->fillBlanks();

        expect($first['filled'])->not->toBe([])
            ->and($second['filled'])->toBe([])
            ->and(file_get_contents($path))->toBe($contents);
    });

    it('fails clearly when the file does not exist', function (): void {
        (new ReverbEnvSetup(sys_get_temp_dir().'/reverb-env-setup-missing'))->fillBlanks();
    })->throws(RuntimeException::class, 'Environment file not found');
});

describe('app:setup-reverb-env command', function (): void {
    it('fills blanks in the environment file and reports without leaking secrets', function (): void {
        $dir = sys_get_temp_dir().'/reverb-env-setup-cmd';
        @mkdir($dir);
        file_put_contents($dir.'/.env', "APP_NAME=KobenLabs\nREVERB_APP_KEY=\nREVERB_HOST=\n");

        $originalPath = app()->environmentPath();

        try {
            app()->useEnvironmentPath($dir);

            $this->artisan('app:setup-reverb-env')
                ->assertSuccessful()
                ->expectsOutputToContain('REVERB_APP_KEY')
                ->doesntExpectOutputToContain('localhost');

            $contents = file_get_contents($dir.'/.env');

            expect($contents)->toMatch('/^REVERB_APP_KEY=[a-z0-9]{20}$/m')
                ->toContain('REVERB_HOST="localhost"');

            // The generated secret must appear in the file exactly once: stored,
            // never printed, never duplicated.
            preg_match('/^REVERB_APP_KEY=([a-z0-9]{20})$/m', $contents, $matches);
            expect(substr_count($contents, $matches[1]))->toBe(1);
        } finally {
            app()->useEnvironmentPath($originalPath);
        }
    });

    it('fails clearly when the environment file is missing', function (): void {
        $dir = sys_get_temp_dir().'/reverb-env-setup-cmd-missing';
        @mkdir($dir);
        @unlink($dir.'/.env');

        $originalPath = app()->environmentPath();

        try {
            app()->useEnvironmentPath($dir);

            $this->artisan('app:setup-reverb-env')->assertFailed();
        } finally {
            app()->useEnvironmentPath($originalPath);
            @rmdir($dir);
        }
    });
});

describe('ReverbHealth widget', function (): void {
    it('shows only to staff when the Reverb key or host is blank', function (): void {
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->create();

        config()->set('broadcasting.connections.reverb.key', '');
        config()->set('broadcasting.connections.reverb.options.host', 'localhost');

        $this->actingAs($admin);
        expect(ReverbHealth::canView())->toBeTrue();

        $this->actingAs($student);
        expect(ReverbHealth::canView())->toBeFalse();
    });

    it('stays hidden once the key and host are set', function (): void {
        $admin = User::factory()->admin()->create();

        config()->set('broadcasting.connections.reverb.key', 'rtfxtbyoef2h7zfoagtq');
        config()->set('broadcasting.connections.reverb.options.host', 'localhost');

        $this->actingAs($admin);
        expect(ReverbHealth::canView())->toBeFalse();
    });
});
