<?php

namespace App\Filament\Pages;

use App\Enums\LabClientState;
use App\Enums\UserRole;
use App\Filament\Resources\Pcs\Tables\PcsTable;
use App\Jobs\RecordAuditEntry;
use App\Models\Pc;
use App\Models\User;
use App\Services\ScreenAccessService;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Live Screens — what the lab PCs are showing, right now.
 *
 * The first Page in this app with a custom Blade view. Everything else composes a
 * Filament schema, which does not suit a grid of continuously-refreshing images:
 * a schema rebuilds on every Livewire request, and here the frames are fetched by
 * the browser over plain HTTP rather than through Livewire at all.
 *
 * Which machines appear is decided entirely by ScreenAccessService, which is also
 * what PcPolicy::viewScreen calls. Those must stay the same rule — when they were
 * not, the legacy app's grid offered a filtered list while its socket had already
 * transmitted every frame, so the filtering was decoration rather than a control.
 *
 * No FileUpload here, so RestrictsFileUploadsToSchemaComponents is not needed (every
 * other custom Page in this app carries it).
 */
class LiveScreens extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-signal';

    protected static ?string $navigationLabel = 'Live Screens';

    // Filament 5 types this as UnitEnum|string|null, not ?string — declaring it
    // narrower is a fatal error at class load, not a lint warning.
    protected static string|UnitEnum|null $navigationGroup = 'Laboratory';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Live Screens';

    protected static ?string $slug = 'monitoring';

    /**
     * Which slice of the lab to show.
     *
     * The chips above the grid. Worth having on its own account: with thirty machines the
     * question someone opens this page with is usually "which one needs looking at", and
     * answering it meant reading thirty badges. Filtering down to the two or three that
     * are not reporting gets there in one click.
     */
    public string $filter = 'all';

    protected string $view = 'filament.pages.live-screens';

    /**
     * Staff only. Students are refused here as well as by viewScreen on every frame,
     * so there is no path to the page that reaches a policy.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->canManageLab() ?? false;
    }

    public function mount(): void
    {
        if (! config('lab.monitoring.enabled')) {
            return;
        }

        // Recorded once per visit, not once per frame fetch. The tiles refresh every
        // second, so auditing at the point of fetching would write thousands of
        // identical rows a minute and bury the entries that matter.
        RecordAuditEntry::dispatch(
            action: 'screen.view',
            auditableType: self::class,
            auditableId: null,
            properties: ['scope' => $this->scopeLabel()],
            userId: auth()->id(),
            ipAddress: request()?->ip(),
            userAgent: request()?->userAgent(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = auth()->user();

        $tiles = $this->visiblePcs($user)
            ->map(fn (Pc $pc): array => $this->tileFor($pc))
            ->all();

        return [
            'pcs' => $this->applyFilter($tiles),

            // Counted before filtering, so the chips keep showing the size of the whole
            // lab and not the size of whatever is currently selected - otherwise choosing
            // a filter silently hides the other two counts and the chips stop being a way
            // back out.
            'filters' => $this->filterChips($tiles),
            'enabled' => (bool) config('lab.monitoring.enabled'),
            'interval' => (float) config('lab.monitoring.interval_seconds'),
            'scopeLabel' => $this->scopeLabel(),
            'isAdmin' => $user->role === UserRole::Admin,
            'totalLabPcs' => Pc::query()->count(),

            // Administrators get a per-machine view of which clients are reporting, so
            // "which machines need attention" is answerable without running a command.
            // A teacher does not: they watch their own class, and the enrolment state
            // of the whole lab is an administrator's concern.
            'clientStatus' => $user->role === UserRole::Admin ? $this->clientStatus() : null,
            'captureSettings' => $this->captureSettings(),
        ];
    }

    /**
     * The chips, each with the number of machines behind it.
     *
     * @param  array<int, array<string, mixed>>  $tiles
     * @return array<string, array{label: string, count: int}>
     */
    private function filterChips(array $tiles): array
    {
        $count = fn (callable $test): int => count(array_filter($tiles, $test));

        return [
            'all' => [
                'label' => 'All',
                'count' => count($tiles),
            ],
            'live' => [
                'label' => 'Reporting',
                'count' => $count(fn (array $tile): bool => $tile['live']),
            ],
            'attention' => [
                'label' => 'Not reporting',
                'count' => $count(fn (array $tile): bool => ! $tile['live'] && $tile['enrolled']),
            ],
            'none' => [
                'label' => 'Not enrolled',
                'count' => $count(fn (array $tile): bool => ! $tile['enrolled']),
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $tiles
     * @return array<int, array<string, mixed>>
     */
    private function applyFilter(array $tiles): array
    {
        // An unknown value falls back to the whole lab rather than to nothing. A chip that
        // filtered everything would read as an empty lab, which is the one reading of this
        // page that is never true.
        return match ($this->filter) {
            'live' => array_values(array_filter($tiles, fn (array $tile): bool => $tile['live'])),
            'attention' => array_values(array_filter($tiles, fn (array $tile): bool => ! $tile['live'] && $tile['enrolled'])),
            'none' => array_values(array_filter($tiles, fn (array $tile): bool => ! $tile['enrolled'])),
            default => $tiles,
        };
    }

    /**
     * Every lab PC grouped by how its client is doing, for administrators.
     *
     * Grouped rather than listed flat because each bucket needs a different response,
     * and a single number would send someone to the wrong machine: a client that has
     * never checked in wants its code and server URL checked, whereas one that went
     * silent wants the machine looked at.
     *
     * @return array<string, array{label: string, guidance: ?string, settled: bool, machines: array<int, string>}>
     */
    private function clientStatus(): array
    {
        $machines = Pc::query()
            ->with('client')
            ->orderBy('name')
            ->get();

        $grouped = [];

        foreach (LabClientState::cases() as $state) {
            $grouped[$state->value] = [
                'label' => $state->label(),
                'guidance' => $state->guidance(),
                // Carried rather than re-derived, so the filter and the rendered panel
                // cannot disagree about which states are worth an administrator's time.
                'settled' => ! $state->needsAttention(),
                'machines' => [],
            ];
        }

        foreach ($machines as $pc) {
            $grouped[PcsTable::clientState($pc)->value]['machines'][] = $pc->name;
        }

        // Only buckets that actually need attention. Reporting machines are the
        // expected state, and listing 30 of them tells an administrator nothing.
        // ARRAY_FILTER_USE_BOTH hands the callback ($value, $key). Both parameter names
        // are used positionally — declaring them the other way round is a type error,
        // not a silent swap, which is what makes this worth spelling out.
        return array_filter(
            $grouped,
            fn (array $group): bool => $group['machines'] !== [] && ! $group['settled'],
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Read-only view of the capture settings, so an administrator can see what a lab
     * is configured to do without opening .env.
     *
     * Display only. These are env-backed values that the server hands to each client
     * on request, so editing them here would need a persistence layer and cache
     * invalidation to have any effect — a settings page for one feature is not worth
     * either. Changing them is a .env edit and a Reverb/Laravel restart.
     *
     * @return array<string, string>
     */
    private function captureSettings(): array
    {
        return [
            'interval' => rtrim(rtrim(number_format((float) config('lab.monitoring.interval_seconds'), 2, '.', ''), '0'), '.').'s per frame',
            'width' => ((int) config('lab.monitoring.max_width')).'px wide',
            'quality' => 'JPEG quality '.config('lab.monitoring.jpeg_quality'),
            'enabled' => config('lab.monitoring.enabled') ? 'On' : 'Off',
        ];
    }

    /**
     * @return Collection<int, Pc>
     */
    private function visiblePcs(User $user)
    {
        return app(ScreenAccessService::class)->viewablePcs($user);
    }

    /**
     * Shape one tile.
     *
     * The student's name comes from the PC's own session, resolved server-side. The
     * legacy grid interpolated an agent-supplied `ip_address` straight into an HTML
     * attribute and an onclick handler, which was a script injection vector from any
     * host on the network.
     *
     * @return array<string, mixed>
     */
    private function tileFor(Pc $pc): array
    {
        $client = $pc->client;
        $occupant = $pc->activeSessions->first()?->user;

        $live = $client !== null
            && $client->isActive()
            && $client->hasFreshFrame()
            && ! $client->isSilent();

        return [
            'id' => (int) $pc->getKey(),
            'name' => $pc->name,
            'ip' => $pc->ip_address,

            // Null rather than a placeholder when the machine is free, so the tile can
            // say "free" instead of naming a student who is not there.
            'student' => $occupant?->full_name,
            'enrolled' => $client !== null && $client->isActive(),
            'live' => $live,
            'lastFrameAt' => $client?->last_frame_at?->toIso8601String(),
            'frameUrl' => route('monitoring.frames.show', $pc),

            // A Filament colour token, not a Tailwind class.
            //
            // The tile's badge is rendered by <x-filament::badge>, which resolves these
            // against Filament's own palette. Hand-writing `bg-success-500` instead
            // produced a class that does not exist in this build - this app's stylesheet
            // is entirely greyscale, 202 utility classes in total, none of them a colour -
            // so a "live" machine's badge and status dot silently rendered in the default
            // text colour. Green here has to come from Filament's tokens or not at all.
            'color' => $live ? 'success' : ($client !== null && $client->isActive() ? 'warning' : 'gray'),
            'statusLabel' => $live
                ? 'live'
                : (($client !== null && $client->isActive()) ? 'no signal' : 'not enrolled'),
        ];
    }

    private function scopeLabel(): string
    {
        $user = auth()->user();

        if ($user === null) {
            return 'unknown';
        }

        return $user->role === UserRole::Admin
            ? 'all lab PCs (administrator)'
            : 'PCs occupied by your class';
    }
}
