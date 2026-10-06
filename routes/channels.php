<?php

use App\Models\GroupChat;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channel authorization
|--------------------------------------------------------------------------
|
| The legacy socket had none of this. Any client could open a connection,
| register itself as "teacher", and receive every broadcast the server sent -
| PC status, class sessions, and student attendance. Reverb's auth endpoint
| means the server decides, per channel, per request.
|
| Every channel below is private. There is deliberately no public channel: an
| unauthenticated client cannot be allowed to observe lab activity at all.
|
| Re-reads these rules on every subscribe, so revoking access takes effect on the
| next attempt rather than when a client reconnects.
|
*/

// PC inventory and occupancy. Staff only — a student has no business seeing the
// state of every machine in the lab.
Broadcast::channel('pcs', function (User $user) {
    return $user->canManageLab();
});

/*
 * Class session lifecycle and attendance.
 *
 * Students are allowed here because the student panel needs to be told when a
 * class starts — that is what makes the Join Class button appear without a
 * manual refresh. `StudentJoinedClass` rides the same channel, so a student can
 * therefore see that other students joined. That is roster information the legacy
 * app already showed to every signed-in user via an unauthenticated endpoint; the
 * row itself is still fetched through AttendancePolicy, which only lets the
 * session's own teacher read it.
 *
 * Narrowing this channel per session, so a student only learns about the class
 * they are in, is the obvious next tightening.
 */
Broadcast::channel('class-sessions', function (User $user) {
    return $user->canAuthenticate();
});

/*
 * Group chat transcripts.
 *
 * The group id is carried in the channel name, so the policy is asked about a real
 * GroupChat rather than a bare id, and a client cannot subscribe to a group it
 * does not belong to.
 */
Broadcast::channel('groups.{groupId}', function (User $user, int $groupId) {
    $group = GroupChat::query()->find($groupId);

    return $group !== null && $user->can('view', $group);
});
