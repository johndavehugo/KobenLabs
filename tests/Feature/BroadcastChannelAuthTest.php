<?php

use App\Models\GroupChat;
use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Broadcast channel authorization.
 *
 * This is security must-fix item 7. The legacy socket had no authentication at all:
 * any client could connect, register itself as a teacher, and receive every
 * broadcast the server emitted — PC status, class sessions, student attendance.
 *
 * Reverb delegates the decision to the server, per channel, per request.
 *
 * Note on the test surface: `phpunit.xml` sets `BROADCAST_CONNECTION=null`, and the
 * null broadcaster's `auth()` is an empty method — it authorizes everything. Hitting
 * `/broadcasting/auth` under it would return 200 for every channel and prove nothing.
 * These tests therefore drive `verifyUserCanAccessChannel()` directly, which is the
 * shared logic every real broadcaster (Reverb, Pusher, Redis) uses to match a channel
 * against the rules in `routes/channels.php` and deny by default when nothing matches.
 */
beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->teacher = User::factory()->teacher()->create();
    $this->student = User::factory()->student()->create();
});

/**
 * Run the registered channel rules for a user. Throws AccessDeniedHttpException when
 * refused, which is the 403 the auth endpoint turns into.
 */
function authorizeChannel(User $user, string $channel): bool
{
    $broadcaster = Broadcast::driver();

    expect($broadcaster)->toBeInstanceOf(Broadcaster::class);

    $request = Request::create('/broadcasting/auth', 'POST');
    $request->setUserResolver(fn () => $user);

    $method = new ReflectionMethod($broadcaster, 'verifyUserCanAccessChannel');
    $method->setAccessible(true);

    try {
        // Returns void on success; reaching here without throwing is authorization.
        $method->invoke($broadcaster, $request, $channel);

        return true;
    } catch (AccessDeniedHttpException) {
        return false;
    }
}

it('lets staff subscribe to the PC channel', function () {
    expect(authorizeChannel($this->admin, 'pcs'))->toBeTrue()
        ->and(authorizeChannel($this->teacher, 'pcs'))->toBeTrue();
});

it('keeps students off the PC channel', function () {
    // PC inventory and occupancy are not a student's business.
    expect(authorizeChannel($this->student, 'pcs'))->toBeFalse();
});

it('lets any signed-in user subscribe to class sessions', function () {
    // The student panel needs to be told when a class starts, which is what makes
    // the Join Class button appear without a manual refresh.
    expect(authorizeChannel($this->student, 'class-sessions'))->toBeTrue()
        ->and(authorizeChannel($this->admin, 'class-sessions'))->toBeTrue();
});

it('lets a member subscribe to their own group', function () {
    $group = GroupChat::factory()->create();
    $group->join($this->student);

    expect(authorizeChannel($this->student, 'groups.'.$group->id))->toBeTrue();
});

it('keeps a non-member off a group channel', function () {
    $group = GroupChat::factory()->create();
    $group->join($this->student);

    $outsider = User::factory()->student()->create();

    expect(authorizeChannel($outsider, 'groups.'.$group->id))->toBeFalse();
});

it('refuses a channel for a group that does not exist', function () {
    // Otherwise a client could probe whether a given group id exists by watching for
    // an allow versus a deny.
    expect(authorizeChannel($this->student, 'groups.999999'))->toBeFalse();
});

it('lets staff read any group channel for moderation', function () {
    $group = GroupChat::factory()->create();
    $group->join($this->student);

    expect(authorizeChannel($this->admin, 'groups.'.$group->id))->toBeTrue();
});

it('denies any channel that has no rule at all', function () {
    // The deny-by-default case. A channel name nobody declared must not be
    // subscribable, or a typo in an event would quietly open a channel.
    expect(authorizeChannel($this->admin, 'something-never-declared'))->toBeFalse()
        ->and(authorizeChannel($this->admin, ''))->toBeFalse();
});

it('keeps a suspended account off every channel', function () {
    $suspended = User::factory()->student()->suspended()->create();

    // A suspended user cannot sign in, so they cannot obtain a socket at all; this
    // pins that the rules agree rather than relying on the login page.
    expect(authorizeChannel($suspended, 'class-sessions'))->toBeFalse()
        ->and(authorizeChannel($suspended, 'pcs'))->toBeFalse();
});
