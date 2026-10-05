<?php

use App\Enums\PcSessionStatus;
use App\Enums\PcStatus;
use App\Events\PcStatusUpdated;
use App\Models\Pc;
use App\Models\PcSession;
use App\Models\User;
use App\Services\PcPresenceService;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| PC presence
|--------------------------------------------------------------------------
|
| Covers the lab-machine lifecycle that the legacy app handled inline inside
| AuthController: detecting which machine a sign-in came from, opening and
| closing the occupancy record, and collecting machines that went quiet.
|
*/

/*
|--------------------------------------------------------------------------
| Detection
|--------------------------------------------------------------------------
*/

it('opens a session on the machine the sign-in came from', function () {
    Event::fake([PcStatusUpdated::class]);

    $pc = Pc::factory()->create(['ip_address' => '192.168.1.50']);
    $user = User::factory()->student()->create();

    $detected = app(PcPresenceService::class)->recordLogin($user, '192.168.1.50');

    expect($detected)->not->toBeNull()
        ->and($detected->is($pc))->toBeTrue()
        ->and($pc->refresh()->status)->toBe(PcStatus::Online)
        ->and($pc->last_seen_at)->not->toBeNull();

    $session = PcSession::query()->sole();

    expect($session->user_id)->toBe($user->id)
        ->and($session->pc_id)->toBe($pc->id)
        ->and($session->status)->toBe(PcSessionStatus::Active);

    Event::assertDispatched(PcStatusUpdated::class);
});

it('ignores a sign-in from an unregistered address', function () {
    $user = User::factory()->student()->create();

    expect(app(PcPresenceService::class)->recordLogin($user, '10.0.0.9'))->toBeNull()
        ->and(PcSession::query()->count())->toBe(0)
        ->and(Pc::query()->where('status', PcStatus::Online)->count())->toBe(0);
});

it('ignores a sign-in from loopback so the server host does not bring a lab pc online', function () {
    Pc::factory()->create(['ip_address' => '127.0.0.1']);
    $user = User::factory()->student()->create();

    expect(app(PcPresenceService::class)->recordLogin($user, '127.0.0.1'))->toBeNull()
        ->and(app(PcPresenceService::class)->recordLogin($user, '::1'))->toBeNull()
        ->and(PcSession::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Occupancy
|--------------------------------------------------------------------------
*/

it('closes any previous occupancy when a user signs in again', function () {
    $first = Pc::factory()->create();
    $second = Pc::factory()->create();
    $user = User::factory()->student()->create();

    $presence = app(PcPresenceService::class);

    $presence->recordLogin($user, $first->ip_address);
    $presence->recordLogin($user, $second->ip_address);

    expect(PcSession::query()->count())->toBe(2)
        ->and(PcSession::query()->active()->count())->toBe(1)
        ->and(PcSession::query()->active()->sole()->pc_id)->toBe($second->id);
});

it('closes any stale occupancy of a machine when someone else signs into it', function () {
    $pc = Pc::factory()->create();
    $previous = User::factory()->student()->create();
    $newcomer = User::factory()->student()->create();

    $presence = app(PcPresenceService::class);
    $presence->recordLogin($previous, $pc->ip_address);
    $presence->recordLogin($newcomer, $pc->ip_address);

    expect(PcSession::query()->count())->toBe(2)
        ->and(PcSession::query()->active()->sole()->user_id)->toBe($newcomer->id)
        ->and(PcSession::query()
            ->where('user_id', $previous->id)
            ->sole()
            ->status)->toBe(PcSessionStatus::Completed);
});

it('releases the machine on logout and derives the duration', function () {
    $pc = Pc::factory()->create();
    $user = User::factory()->student()->create();

    $presence = app(PcPresenceService::class);
    $presence->recordLogin($user, $pc->ip_address);

    PcSession::query()->sole()->forceFill(['started_at' => now()->subMinutes(40)])->save();

    $presence->recordLogout($user);

    $session = PcSession::query()->sole();

    expect($session->status)->toBe(PcSessionStatus::Completed)
        ->and($session->duration_minutes)->toBe(40)
        ->and($session->ended_at)->not->toBeNull()
        ->and($pc->refresh()->status)->toBe(PcStatus::Offline)
        ->and($pc->last_seen_at)->toBeNull();
});

it('does not release a machine another user has since claimed', function () {
    $pc = Pc::factory()->create();
    $leaving = User::factory()->student()->create();
    $staying = User::factory()->student()->create();

    $presence = app(PcPresenceService::class);
    $presence->recordLogin($leaving, $pc->ip_address);

    // A second user takes the machine in the same instant the first leaves.
    PcSession::query()->create([
        'user_id' => $staying->id,
        'pc_id' => $pc->id,
        'started_at' => now(),
        'status' => PcSessionStatus::Active,
    ]);

    $presence->recordLogout($leaving);

    expect($pc->refresh()->status)->toBe(PcStatus::Online)
        ->and(PcSession::query()->active()->sole()->user_id)->toBe($staying->id);
});

it('records a heartbeat against the machine in use', function () {
    $pc = Pc::factory()->create();
    $user = User::factory()->student()->create();

    $presence = app(PcPresenceService::class);
    $presence->recordLogin($user, $pc->ip_address);

    $pc->forceFill(['last_seen_at' => now()->subMinutes(30)])->save();

    $presence->recordHeartbeat($user);

    expect($pc->refresh()->last_seen_at->isAfter(now()->subMinute()))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Stale sweep
|--------------------------------------------------------------------------
*/

it('collects a pc that stopped checking in', function () {
    $stale = Pc::factory()->stale()->create();
    $healthy = Pc::factory()->online()->create();

    expect(app(PcPresenceService::class)->markStalePcsOffline())->toBe(1)
        ->and($stale->refresh()->status)->toBe(PcStatus::Offline)
        ->and($stale->last_seen_at)->toBeNull()
        ->and($healthy->refresh()->status)->toBe(PcStatus::Online);
});

it('never auto-offlines a pc in maintenance', function () {
    $maintenance = Pc::factory()->maintenance()->create();

    Pc::query()->whereKey($maintenance->id)->update([
        'last_seen_at' => now()->subDay(),
    ]);

    expect(app(PcPresenceService::class)->markStalePcsOffline())->toBe(0)
        ->and($maintenance->refresh()->status)->toBe(PcStatus::Maintenance);
});

it('honours a threshold override', function () {
    $pc = Pc::factory()->online()->create();
    $pc->forceFill(['last_seen_at' => now()->subMinutes(20)])->save();

    $presence = app(PcPresenceService::class);

    // Idle 20 minutes: inside a 30 minute window, outside a 10 minute one.
    expect($presence->markStalePcsOffline(30))->toBe(0)
        ->and($pc->refresh()->status)->toBe(PcStatus::Online)
        ->and($presence->markStalePcsOffline(10))->toBe(1)
        ->and($pc->refresh()->status)->toBe(PcStatus::Offline);
});

/*
|--------------------------------------------------------------------------
| Command
|--------------------------------------------------------------------------
*/

it('reports what a sweep would change without writing', function () {
    $stale = Pc::factory()->stale()->create(['name' => 'Lab Computer 9']);

    $this->artisan('pcs:mark-stale-offline', ['--dry-run' => true])
        ->assertSuccessful();

    expect($stale->refresh()->status)->toBe(PcStatus::Online);
});

it('collects idle pcs when run', function () {
    $stale = Pc::factory()->stale()->create();

    $this->artisan('pcs:mark-stale-offline')
        ->assertSuccessful();

    expect($stale->refresh()->status)->toBe(PcStatus::Offline);
});

/*
|--------------------------------------------------------------------------
| Listeners
|--------------------------------------------------------------------------
*/

it('brings the pc online through the login listener', function () {
    Event::fake([PcStatusUpdated::class]);

    $pc = Pc::factory()->create(['ip_address' => '192.168.1.77']);
    $user = User::factory()->student()->create();

    request()->server->set('REMOTE_ADDR', '192.168.1.77');

    event(new Login('web', $user, false));

    expect($pc->refresh()->status)->toBe(PcStatus::Online)
        ->and(PcSession::query()->where('user_id', $user->id)->active()->count())->toBe(1);
});

it('stamps the login time and releases the pc on logout', function () {
    $pc = Pc::factory()->create(['ip_address' => '192.168.1.78']);
    $user = User::factory()->student()->create();

    request()->server->set('REMOTE_ADDR', '192.168.1.78');

    event(new Login('web', $user, false));

    expect($user->refresh()->last_login_at)->toBeNull();

    event(new Logout('web', $user));

    expect($user->refresh()->last_login_at)->not->toBeNull()
        ->and($pc->refresh()->status)->toBe(PcStatus::Offline);
});
