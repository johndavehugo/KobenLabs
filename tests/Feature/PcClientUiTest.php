<?php

use App\Enums\PcClientState;
use App\Filament\Resources\Pcs\Pages\ListPcs;
use App\Models\Pc;
use App\Models\PcClient;
use App\Models\User;
use App\Services\PcClientEnrolment;
use App\Services\ScreenFrameService;
use Illuminate\Support\Facades\Storage;

/**
|--------------------------------------------------------------------------
| Enrolling a Lab Client from the admin panel
|--------------------------------------------------------------------------
|
| The button is the path a human takes, so these tests are mostly about refusals:
| a student must not be able to enrol a machine, because enrolling is what grants it
| the right to send screenshots to staff.
|
| Also covers the credential handling itself, because that is where the app has already
| been bitten once — `pcs:enrol-client` stored a live credential in token_hash instead
| of a digest of it, and every test passed because the command was the only caller.
| PcClientEnrolment now holds that logic for both the command and these buttons.
 */
describe('the Enrol client button', function (): void {
    it('offers it to staff', function (): void {
        $this->actingAs(User::factory()->admin()->create());
        $pc = Pc::factory()->create(['name' => 'LAB-01']);

        livewire(ListPcs::class)
            ->assertTableActionVisible('enrolClient', $pc);
    });

    it('offers it to a teacher, who supervises a class', function (): void {
        $this->actingAs(User::factory()->teacher()->create());
        $pc = Pc::factory()->create();

        livewire(ListPcs::class)
            ->assertTableActionVisible('enrolClient', $pc);
    });

    it('refuses a student', function (): void {
        $this->actingAs(User::factory()->student()->create());
        $pc = Pc::factory()->create();

        // A student sees the inventory so they can find a free machine, which is why
        // PcPolicy::view is open to everyone. Enrolling is not part of that: it grants
        // a machine the right to send a screen to staff.
        livewire(ListPcs::class)
            ->assertTableActionHidden('enrolClient', $pc);
    });

    it('generates a code when opened', function (): void {
        Storage::fake();

        $this->actingAs(User::factory()->admin()->create());
        $pc = Pc::factory()->create();

        livewire(ListPcs::class)
            ->callTableAction('enrolClient', $pc)
            ->assertHasNoActionErrors();

        expect($pc->fresh()->client)->not->toBeNull();
    });

    it('shows the code in the modal', function (): void {
        Storage::fake();

        $this->actingAs(User::factory()->admin()->create());
        $pc = Pc::factory()->create();

        // The plaintext is only ever in the modal, so this asserts on the one moment
        // an administrator could copy it. Asserted loosely on shape rather than the
        // exact value, since the token is random.
        livewire(ListPcs::class)
            ->mountTableAction('enrolClient', $pc)
            ->assertSchemaStateSet(
                fn (array $state): bool => is_string($state['token'] ?? null) && strlen($state['token']) === 40,
            );
    });

    it('never stores the code in plain text', function (): void {
        Storage::fake();

        $this->actingAs(User::factory()->admin()->create());
        $pc = Pc::factory()->create();

        livewire(ListPcs::class)
            ->callTableAction('enrolClient', $pc);

        $stored = $pc->fresh()->client->token_hash;

        // 40 base64url characters is the token; 64 hex characters is its SHA-256.
        expect($stored)->toHaveLength(64)
            ->and($stored)->toMatch('/^[0-9a-f]{64}$/');
    });

    it('replaces the old code when a machine is re-enrolled', function (): void {
        Storage::fake();

        $this->actingAs(User::factory()->admin()->create());
        $pc = Pc::factory()->create();

        $first = app(PcClientEnrolment::class)->enrol($pc)['token'];
        $firstHash = $pc->fresh()->client->token_hash;

        livewire(ListPcs::class)
            ->callTableAction('enrolClient', $pc);

        expect($pc->fresh()->client->token_hash)->not->toBe($firstHash)
            // The old code is dead, which is what stops a decommissioned or replaced
            // machine continuing to report under a credential nobody remembers.
            ->and(PcClient::findByToken($first))->toBeNull();
    });

    it('brings a revoked machine back', function (): void {
        Storage::fake();

        $this->actingAs(User::factory()->admin()->create());
        $pc = Pc::factory()->create();
        $client = PcClient::factory()->for($pc)->revoked()->create();

        livewire(ListPcs::class)
            ->callTableAction('enrolClient', $pc);

        expect($client->fresh()->state)->toBe(PcClientState::Active)
            ->and($client->fresh()->revoked_at)->toBeNull();
    });
});

describe('the Revoke client button', function (): void {
    it('is hidden on a machine that was never enrolled', function (): void {
        $this->actingAs(User::factory()->admin()->create());
        $pc = Pc::factory()->create();

        // Hidden rather than disabled: a dead button on every row of a 30-PC table is
        // noise, and the badge already says the machine is not enrolled.
        livewire(ListPcs::class)
            ->assertTableActionHidden('revokeClient', $pc);
    });

    it('is hidden for a student', function (): void {
        $this->actingAs(User::factory()->student()->create());
        $pc = Pc::factory()->create();
        PcClient::factory()->for($pc)->create();

        livewire(ListPcs::class)
            ->assertTableActionHidden('revokeClient', $pc);
    });

    it('stops the machine reporting and deletes its frame', function (): void {
        Storage::fake();

        $this->actingAs(User::factory()->admin()->create());
        $pc = Pc::factory()->create();
        $client = PcClient::factory()->for($pc)->withFreshFrame()->create();
        $token = $client->issueToken();

        $path = app(ScreenFrameService::class)->pathFor((int) $pc->getKey());
        Storage::disk(config('lab.storage.default_disk'))->put($path, jpegBytes());

        livewire(ListPcs::class)
            ->callTableAction('revokeClient', $pc);

        // Revocation has to take effect immediately, not on the client's next upload:
        // findByToken refuses anything not in the active state.
        expect(PcClient::findByToken($token))->toBeNull()
            // A machine nobody may watch should not leave its last screenshot behind.
            ->and(Storage::disk(config('lab.storage.default_disk'))->exists($path))->toBeFalse();
    });
});

describe('the Lab Client column', function (): void {
    it('shows a machine with no client as not enrolled', function (): void {
        $this->actingAs(User::factory()->admin()->create());
        Pc::factory()->create(['name' => 'LAB-01']);

        livewire(ListPcs::class)
            ->assertSee('Not enrolled');
    });

    it('shows a reporting machine as reporting', function (): void {
        $this->actingAs(User::factory()->admin()->create());
        $pc = Pc::factory()->create(['name' => 'LAB-01']);
        PcClient::factory()->for($pc)->withFreshFrame()->create();

        livewire(ListPcs::class)
            ->assertSee('Reporting');
    });

    it('distinguishes never-seen from stopped', function (): void {
        $this->actingAs(User::factory()->admin()->create());

        $neverSeen = Pc::factory()->create(['name' => 'LAB-NEVER']);
        PcClient::factory()->for($neverSeen)->neverSeen()->create();

        $silent = Pc::factory()->create(['name' => 'LAB-SILENT']);
        PcClient::factory()->for($silent)->silent()->create();

        // Two different problems with two different fixes — wrong code or wrong server
        // URL versus a machine that needs looking at — so they must not read the same.
        livewire(ListPcs::class)
            ->assertSee('Never checked in')
            ->assertSee('Silent');
    });

    it('shows a revoked machine as revoked', function (): void {
        $this->actingAs(User::factory()->admin()->create());
        $pc = Pc::factory()->create();
        PcClient::factory()->for($pc)->revoked()->create();

        livewire(ListPcs::class)
            ->assertSee('Revoked');
    });
});
