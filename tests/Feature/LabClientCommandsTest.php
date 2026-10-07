<?php

use App\Enums\PcClientState;
use App\Models\Pc;
use App\Models\PcClient;
use App\Services\ScreenFrameService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

/**
|--------------------------------------------------------------------------
| Lab Client administration commands
|--------------------------------------------------------------------------
|
| These are the only way a machine gets the credential it needs, so they are the
| whole enrolment story — there is no self-service path, by design. Legacy's relay
| accepted `agent_register` from anything that connected, which meant the enrolment
| step was unowned and unauthenticated.
|
| Worth noting what these tests cannot prove: that a real Windows machine accepts the
| scheduled task, or that the client uploads. `install-client.ps1` is exercised by
| hand on a lab PC.
 */
describe('pcs:enrol-client', function (): void {
    it('issues a working token', function (): void {
        $pc = Pc::factory()->create(['name' => 'LAB-01']);

        $this->artisan('pcs:enrol-client', ['pc' => 'LAB-01'])
            ->assertSuccessful();

        $client = $pc->fresh()->client;

        expect($client)->not->toBeNull()
            ->and($client->state)->toBe(PcClientState::Active);

        // The column holds the 64-character SHA-256 of the token, never the token
        // itself, so it cannot be replayed off a database dump.
        expect($client->token_hash)->toHaveLength(64)
            ->and($client->token_hash)->toMatch('/^[0-9a-f]{64}$/');
    });

    it('accepts a numeric id as well as a name', function (): void {
        $pc = Pc::factory()->create(['name' => 'LAB-02']);

        $this->artisan('pcs:enrol-client', ['pc' => (string) $pc->getKey()])
            ->assertSuccessful();

        expect($pc->fresh()->client)->not->toBeNull();
    });

    it('rotates the credential when re-run, invalidating the old token', function (): void {
        $pc = Pc::factory()->create(['name' => 'LAB-03']);
        $client = PcClient::factory()->for($pc)->create();

        $firstHash = $client->token_hash;

        $this->artisan('pcs:enrol-client', ['pc' => 'LAB-03'])->assertSuccessful();

        expect($pc->fresh()->client->token_hash)->not->toBe($firstHash);
    });

    it('fails clearly for a PC that does not exist', function (): void {
        $this->artisan('pcs:enrol-client', ['pc' => 'NO-SUCH-PC'])
            ->assertFailed();
    });

    it('stores only the digest, never the token itself', function (): void {
        $pc = Pc::factory()->create(['name' => 'LAB-04']);

        $this->artisan('pcs:enrol-client', ['pc' => 'LAB-04'])->assertSuccessful();

        // The plaintext is 40 characters of base64url. The column holds a 64-character
        // SHA-256 hex digest. A 40-character value here would mean the live credential
        // was written to the database — in every backup, and readable by anyone with
        // read access.
        $stored = $pc->fresh()->client->token_hash;

        expect(strlen($stored))->toBe(64)
            ->and($stored)->not->toContain('=')
            ->and(PcClient::TOKEN_LENGTH)->toBe(40);
    });
});

describe('pcs:revoke-client', function (): void {
    beforeEach(function (): void {
        Storage::fake();
    });

    it('revokes the credential and deletes the stored frame', function (): void {
        $pc = Pc::factory()->create(['name' => 'LAB-05']);
        $client = PcClient::factory()->for($pc)->withFreshFrame()->create();

        $path = app(ScreenFrameService::class)->pathFor((int) $pc->getKey());
        Storage::disk(config('lab.storage.default_disk'))->put($path, jpegBytes());

        $this->artisan('pcs:revoke-client', ['pc' => 'LAB-05'])->assertSuccessful();

        // The frame goes too: a decommissioned machine's last screenshot has no
        // reason to stay readable once its credential is withdrawn.
        expect($client->fresh()->state)->toBe(PcClientState::Revoked)
            ->and(Storage::disk(config('lab.storage.default_disk'))->exists($path))->toBeFalse();
    });

    it('tells an administrator when a PC has no client rather than erroring', function (): void {
        Pc::factory()->create(['name' => 'LAB-06']);

        $this->artisan('pcs:revoke-client', ['pc' => 'LAB-06'])->assertSuccessful();
    });

    it('revokes everything with --all', function (): void {
        $first = PcClient::factory()->create();
        $second = PcClient::factory()->create();
        $alreadyGone = PcClient::factory()->revoked()->create();

        $this->artisan('pcs:revoke-client', ['--all' => true])->assertSuccessful();

        expect($first->fresh()->state)->toBe(PcClientState::Revoked)
            ->and($second->fresh()->state)->toBe(PcClientState::Revoked)
            ->and($alreadyGone->fresh()->revoked_at)->not->toBeNull();
    });

    it('requires a PC or --all', function (): void {
        $this->artisan('pcs:revoke-client', [])->assertFailed();
    });
});

describe('pcs:monitoring-status', function (): void {
    it('sorts machines into the groups that need different responses', function (): void {
        Pc::factory()->create(['name' => 'NOT-ENROLLED']);
        Pc::factory()->create(['name' => 'NEVER-SEEN']);
        Pc::factory()->create(['name' => 'SILENT']);
        Pc::factory()->create(['name' => 'REPORTING']);
        Pc::factory()->create(['name' => 'REVOKED']);

        $notEnrolled = Pc::query()->where('name', 'NOT-ENROLLED')->firstOrFail();
        $neverSeen = Pc::query()->where('name', 'NEVER-SEEN')->firstOrFail();
        $silent = Pc::query()->where('name', 'SILENT')->firstOrFail();
        $reporting = Pc::query()->where('name', 'REPORTING')->firstOrFail();
        $revoked = Pc::query()->where('name', 'REVOKED')->firstOrFail();

        PcClient::factory()->for($neverSeen)->neverSeen()->create();
        PcClient::factory()->for($silent)->silent()->create();
        PcClient::factory()->for($reporting)->withFreshFrame()->create();
        PcClient::factory()->for($revoked)->revoked()->create();

        // Artisan::call rather than $this->artisan(), because the latter returns a
        // PendingCommand and does not leave the command's stdout in a buffer we can
        // read back.
        Artisan::call('pcs:monitoring-status', ['--json' => true]);

        $json = json_decode(Artisan::output(), true);

        expect($json)->toBeArray();

        // Four buckets, four different fixes. "Not enrolled" needs an installer run,
        // "never seen" needs the token checked, "silent" needs the machine looked at.
        expect($json['unenrolled'])->toBe(['NOT-ENROLLED'])
            ->and($json['never_seen'])->toBe(['NEVER-SEEN'])
            ->and($json['silent'])->toBe(['SILENT'])
            ->and($json['reporting'])->toBe(['REPORTING'])
            ->and($json['revoked'])->toBe(['REVOKED'])
            ->and($json['total'])->toBe(5);
    });

    it('succeeds on a lab with no machines at all', function (): void {
        $this->artisan('pcs:monitoring-status', ['--json' => true])->assertSuccessful();
    });
});
