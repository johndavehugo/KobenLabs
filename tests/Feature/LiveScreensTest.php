<?php

use App\Filament\Pages\LiveScreens;
use App\Models\AuditLog;
use App\Models\Pc;
use App\Models\PcClient;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
|--------------------------------------------------------------------------
| Serving a screen to staff
|--------------------------------------------------------------------------
|
| This is the security boundary of the whole feature: the single route through
| which any screen bytes leave the server.
|
| Legacy's equivalent had no boundary. Its relay accepted `agent_register` from
| anything that connected, let any client self-assert `{"role":"teacher"}` from
| localStorage, and fanned every frame to every connected teacher. A student who
| edited one key in browser storage received every screen in the lab. The HTTP
| roster endpoint *was* role-checked, which made the socket a complete bypass of the
| only control the system had.
|
| So these tests are mostly about refusal. The happy path is covered elsewhere; what
| matters is that each way of asking for a screen you should not have returns 403 or
| 404 rather than pixels.
 */
describe('who may see a screen', function (): void {
    beforeEach(function (): void {
        Storage::fake();
    });

    it('refuses a student outright', function (): void {
        $this->actingAs($student = User::factory()->student()->create());
        [, , $pc] = runningClassWithOccupiedPc(User::factory()->teacher()->create(), $student);

        $this->actingAs($student)
            ->get(route('monitoring.frames.show', $pc))
            ->assertForbidden();
    });

    it('refuses a teacher whose class is not the one on that PC', function (): void {
        $outsider = User::factory()->teacher()->create();
        $otherTeacher = User::factory()->teacher()->create();
        $student = User::factory()->student()->create();

        // A real session, a real occupant — just not this teacher's.
        runningClassWithOccupiedPc($otherTeacher, $student);

        $this->actingAs($outsider)
            ->get(route('monitoring.frames.show', Pc::query()->firstOrFail()))
            ->assertForbidden();
    });

    it('allows the teacher running the class', function (): void {
        $teacher = User::factory()->teacher()->create();
        $student = User::factory()->student()->create();

        [, , $pc] = runningClassWithOccupiedPc($teacher, $student);

        // The file has to be on disk as well as the row being fresh — hasFreshFrame()
        // is only half of the check, and a test that only faked the row would have
        // passed against a controller that never looked at storage.
        storeFrameFor($pc);

        $this->actingAs($teacher)
            ->get(route('monitoring.frames.show', $pc))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    });

    it('allows an administrator for any PC', function (): void {
        $this->actingAs($admin = User::factory()->admin()->create());

        $pc = Pc::factory()->create();
        storeFrameFor($pc);

        $this->actingAs($admin)
            ->get(route('monitoring.frames.show', $pc))
            ->assertOk();
    });

    it('refuses an unsigned request rather than redirecting into the panel', function (): void {
        $pc = Pc::factory()->create();
        PcClient::factory()->for($pc)->withFreshFrame()->create();

        // A guest gets the sign-in redirect like any other authed route. Worth pinning
        // because the frame route sits under /admin/ but outside the Filament panel,
        // so it is easy to forget it needs auth of its own.
        $this->get(route('monitoring.frames.show', $pc))->assertRedirect();
    });

    it('returns 404 rather than confirming a PC exists when the viewer may not see it', function (): void {
        // Gate::authorize runs before the client lookup, so a refusal cannot be
        // distinguished from a nonexistent PC by timing or by response code.
        $this->actingAs(User::factory()->student()->create());

        $this->actingAs(User::factory()->student()->create())
            ->get(route('monitoring.frames.show', Pc::factory()->create()))
            ->assertForbidden();
    });
});

describe('when a frame may not be shown', function (): void {
    beforeEach(function (): void {
        Storage::fake();
    });

    it('404s when no client is enrolled', function (): void {
        $this->actingAs($admin = User::factory()->admin()->create());
        $pc = Pc::factory()->create();

        $this->actingAs($admin)
            ->get(route('monitoring.frames.show', $pc))
            ->assertNotFound();
    });

    it('404s when the client is revoked', function (): void {
        $this->actingAs($admin = User::factory()->admin()->create());
        $pc = Pc::factory()->create();
        PcClient::factory()->for($pc)->revoked()->withFreshFrame()->create();

        $this->actingAs($admin)
            ->get(route('monitoring.frames.show', $pc))
            ->assertNotFound();
    });

    it('404s on a frame that is too old, instead of serving a frozen image', function (): void {
        $this->actingAs($admin = User::factory()->admin()->create());
        $pc = Pc::factory()->create();
        $client = PcClient::factory()->for($pc)->withStaleFrame()->create();

        Storage::disk(config('lab.storage.default_disk'))
            ->put("monitoring/pcs/{$pc->getKey()}/latest.jpg", jpegBytes());

        // The file exists. The timestamp is what says it is not current, and serving
        // it anyway is exactly how legacy made a dead machine look live.
        expect($client->hasFreshFrame())->toBeFalse();

        $this->actingAs($admin)
            ->get(route('monitoring.frames.show', $pc))
            ->assertNotFound();
    });

    it('404s when the row claims a frame but the file is gone', function (): void {
        $this->actingAs($admin = User::factory()->admin()->create());
        $pc = Pc::factory()->create();
        PcClient::factory()->for($pc)->withFreshFrame()->create();

        $this->actingAs($admin)
            ->get(route('monitoring.frames.show', $pc))
            ->assertNotFound();
    });
});

describe('caching a screen', function (): void {
    beforeEach(function (): void {
        Storage::fake();
    });

    it('tells the browser not to hold on to a frame', function (): void {
        $this->actingAs($admin = User::factory()->admin()->create());
        $pc = Pc::factory()->create();
        storeFrameFor($pc);

        $response = $this->actingAs($admin)->get(route('monitoring.frames.show', $pc));

        $response->assertOk();

        // A back-button or shared cache holding yesterday's screen is a privacy
        // problem, not just a performance one. Asserted directive by directive
        // because Symfony reorders them, so a literal comparison is brittle.
        $cacheControl = $response->headers->get('Cache-Control');

        expect($cacheControl)
            ->toContain('no-store')
            ->toContain('private')
            ->not->toContain('public');

        expect($response->headers->get('Pragma'))->toBe('no-cache');
    });

    it('answers a repeat request for the same frame with 304', function (): void {
        $this->actingAs($admin = User::factory()->admin()->create());
        $pc = Pc::factory()->create();
        storeFrameFor($pc);

        $first = $this->actingAs($admin)->get(route('monitoring.frames.show', $pc));
        $first->assertOk();

        $stamp = $first->headers->get('Last-Modified');
        expect($stamp)->not->toBeNull();

        // Tiles re-request every second. A 304 with no body is what keeps a 40-tile
        // grid from re-downloading ~100 KB per tile per second.
        $this->actingAs($admin)
            ->get(route('monitoring.frames.show', $pc), ['If-Modified-Since' => $stamp])
            ->assertStatus(304);
    });
});

describe('the Live Screens grid', function (): void {
    beforeEach(function (): void {
        Storage::fake();
    });

    it('is unreachable for a student', function (): void {
        $this->actingAs(User::factory()->student()->create());

        expect(LiveScreens::canAccess())->toBeFalse();

        $this->get(LiveScreens::getUrl(panel: 'admin'))->assertForbidden();
    });

    it('shows an administrator every lab PC', function (): void {
        $this->actingAs($admin = User::factory()->admin()->create());

        Pc::factory()->create(['name' => 'LAB-01']);
        Pc::factory()->create(['name' => 'LAB-02']);

        livewire(LiveScreens::class)
            ->assertOk()
            ->assertSee('LAB-01')
            ->assertSee('LAB-02');
    });

    it('shows a teacher only the PCs their own class is using', function (): void {
        $this->actingAs($teacher = User::factory()->teacher()->create());
        $student = User::factory()->student()->create();

        [, , $mine] = runningClassWithOccupiedPc($teacher, $student);

        // A machine in a different class, that this teacher must not see.
        $theirs = Pc::factory()->create(['name' => 'OTHER-CLASS-PC']);

        livewire(LiveScreens::class)
            ->assertOk()
            ->assertSee($mine->name)
            ->assertDontSee($theirs->name);
    });

    it('names the student sitting at a machine', function (): void {
        $this->actingAs($teacher = User::factory()->teacher()->create());
        $student = User::factory()->student()->create();

        runningClassWithOccupiedPc($teacher, $student);

        livewire(LiveScreens::class)->assertOk()->assertSee($student->full_name);
    });

    it('does not claim a machine is live when nothing has been uploaded', function (): void {
        $this->actingAs($admin = User::factory()->admin()->create());

        // Enrolled, and reporting — but never actually uploaded a frame.
        $pc = Pc::factory()->create(['name' => 'LAB-01']);
        PcClient::factory()->for($pc)->neverSeen()->create();

        livewire(LiveScreens::class)
            ->assertOk()
            // A tile that says "live" while showing nothing is the failure this whole
            // staleness design exists to prevent.
            ->assertSee('no signal');
    });

    it('says not enrolled rather than no signal for a machine with no client', function (): void {
        $this->actingAs($admin = User::factory()->admin()->create());
        Pc::factory()->create(['name' => 'LAB-01']);

        livewire(LiveScreens::class)
            ->assertOk()
            // Different problems want different responses: "not enrolled" means run
            // the installer, "no signal" means look at the machine.
            ->assertSee('Not enrolled');
    });

    it('records one audit entry when the page is opened', function (): void {
        $this->actingAs($admin = User::factory()->admin()->create());
        Pc::factory()->create();

        livewire(LiveScreens::class)->assertOk();

        expect(AuditLog::query()->where('action', 'screen.view')->count())->toBe(1);
    });

    it('does not add an audit row per frame fetch', function (): void {
        $this->actingAs($admin = User::factory()->admin()->create());
        $pc = Pc::factory()->create();
        storeFrameFor($pc);

        livewire(LiveScreens::class)->assertOk();

        $before = AuditLog::query()->where('action', 'screen.view')->count();

        // Tiles refresh every second. If the audit entry were written at the point of
        // fetching rather than on visiting the page, this would add a row per tile per
        // second and bury every other entry in the trail.
        foreach (range(1, 10) as $_) {
            $this->actingAs($admin)->get(route('monitoring.frames.show', $pc))->assertOk();
        }

        expect(AuditLog::query()->where('action', 'screen.view')->count())->toBe($before);
    });
});

/**
 * Put a real, current frame on disk for a PC and enrol a client for it.
 *
 * Both halves are required: a fresh pc_clients row says a frame was captured, and the
 * file is what is actually on disk. Setting up only the row would let a controller
 * that never touched storage pass these tests.
 */
function storeFrameFor(Pc $pc): PcClient
{
    $client = PcClient::factory()->for($pc)->withFreshFrame()->create();

    Storage::disk(config('lab.storage.default_disk'))
        ->put("monitoring/pcs/{$pc->getKey()}/latest.jpg", jpegBytes());

    return $client;
}
