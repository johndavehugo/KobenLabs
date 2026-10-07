<?php

use App\Enums\LabClientState;
use App\Filament\Pages\LiveScreens;
use App\Models\Pc;
use App\Models\PcClient;
use App\Models\User;

/**
|--------------------------------------------------------------------------
| The Lab Client status panel
|--------------------------------------------------------------------------
|
| The administrator's answer to "which machines need attention", so that question does
| not require knowing a command name.
|
| Its value is the grouping. Each bucket needs a different fix, and the whole point is
| that a single count sends someone to the wrong machine — so the tests below are
| mostly about not collapsing states that must stay apart.
 */
describe('the Lab Client status panel', function (): void {
    it('tells an administrator which machines have no client', function (): void {
        $this->actingAs(User::factory()->admin()->create());
        Pc::factory()->create(['name' => 'LAB-NEW']);

        livewire(LiveScreens::class)
            ->assertSee('Not enrolled')
            ->assertSee('LAB-NEW');
    });

    it('names what to do about it, rather than leaving it to be worked out', function (): void {
        $this->actingAs(User::factory()->admin()->create());
        Pc::factory()->create(['name' => 'LAB-NEW']);

        livewire(LiveScreens::class)
            ->assertSee('Run the Lab Client installer on this machine.');
    });

    it('separates never-checked-in from silently-stopped', function (): void {
        $this->actingAs(User::factory()->admin()->create());

        $neverSeen = Pc::factory()->create(['name' => 'LAB-NEVER']);
        PcClient::factory()->for($neverSeen)->neverSeen()->create();

        $silent = Pc::factory()->create(['name' => 'LAB-STOPPED']);
        PcClient::factory()->for($silent)->silent()->create();

        // Same underlying predicate (isSilent() is true for both), opposite fixes:
        // wrong code or server URL versus a machine that needs looking at.
        livewire(LiveScreens::class)
            ->assertSee('LAB-NEVER')
            ->assertSee('LAB-STOPPED')
            ->assertSee('Installed, but has never reached the server.')
            ->assertSee('Was reporting and has stopped.');
    });

    it('omits healthy machines, which would otherwise bury the point', function (): void {
        $this->actingAs(User::factory()->admin()->create());

        $healthy = Pc::factory()->create(['name' => 'LAB-HEALTHY']);
        PcClient::factory()->for($healthy)->withFreshFrame()->create();

        Pc::factory()->create(['name' => 'LAB-BROKEN']);

        $html = livewire(LiveScreens::class)->assertOk()->html();

        // Scoped to the status panel itself rather than to "everything above the grid".
        //
        // A healthy machine should still appear in the machine list — it is a real machine
        // being watched — so asserting it is absent from the whole page would be testing
        // the wrong thing. And a future fix that dropped healthy tiles to satisfy this
        // test would be a real regression, so the scope is explicit.
        //
        // Matched on the <details> element that wraps the panel, not on its position.
        // The panel used to sit above the machine grid, so "everything before the grid"
        // identified it; it now sits below the grid inside a disclosure, because a page
        // called Live Screens should open on the machines rather than on diagnostics. A
        // positional cut would have quietly turned into an empty string here and failed
        // for a reason that had nothing to do with the behaviour under test.
        expect($html)->toMatch('/<details\b.*?<\/details>/s');

        preg_match('/<details\b.*?<\/details>/s', $html, $matches);

        $panel = $matches[0];

        expect($panel)
            ->toContain('LAB-BROKEN')
            // Thirty reporting machines in a list of "machines needing attention"
            // tells an administrator nothing.
            ->not->toContain('LAB-HEALTHY');
    });

    it('is not shown to a teacher', function (): void {
        $this->actingAs(User::factory()->teacher()->create());
        Pc::factory()->create(['name' => 'LAB-ADMIN-ONLY']);

        // Enrolment state for the whole lab is an administrator's concern. A teacher
        // watches their own class and has no business seeing a fleet-wide health view.
        livewire(LiveScreens::class)
            ->assertDontSee('LAB-ADMIN-ONLY')
            ->assertDontSee('Lab Clients needing attention');
    });

    it('shows the current capture settings to an administrator', function (): void {
        config()->set('lab.monitoring.interval_seconds', 1.0);
        config()->set('lab.monitoring.max_width', 1280);

        $this->actingAs(User::factory()->admin()->create());

        // Read-only on purpose: these are .env-backed values the server hands to each
        // client, so editing them here would need persistence and cache invalidation to
        // have any effect. A control that looked editable but wrote nothing would be
        // worse than no control.
        livewire(LiveScreens::class)
            ->assertSee('Capture settings')
            ->assertSee('1280px wide')
            ->assertSee('1s per frame');
    });
});

describe('LabClientState', function (): void {
    it('gives a fix for every state that is not healthy', function (): void {
        foreach (LabClientState::cases() as $state) {
            if ($state === LabClientState::Reporting) {
                // Nothing to fix is the honest answer; an empty string would render as a
                // blank line where an instruction should be.
                expect($state->guidance())->toBeNull()
                    ->and($state->needsAttention())->toBeFalse();

                continue;
            }

            expect($state->guidance())->toBeString()->not->toBeEmpty()
                ->and($state->needsAttention())->toBeTrue();
        }
    });

    it('labels every state for display', function (): void {
        foreach (LabClientState::cases() as $state) {
            // A snake_case value leaking into the UI would be the same defect as the
            // badge rendering "not_enrolled" instead of "Not enrolled".
            expect($state->label())->not->toContain('_');
        }
    });
});
