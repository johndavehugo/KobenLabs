<?php

namespace App\Http\Controllers;

use App\Events\ScreenFrameAvailable;
use App\Models\PcClient;
use App\Services\ScreenFrameService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Receive a screen frame from the Lab Client.
 *
 * The body is raw JPEG bytes with `Content-Type: image/jpeg`, deliberately not a
 * multipart upload. A frame is not a file a person chose to send: it has no
 * filename, no extension, and no place in a user's drive, so it skips FileService and
 * its quota accounting entirely and goes to a single overwrite slot per PC.
 *
 * Nothing here trusts the client's self-description. The bytes are checked for a JPEG
 * marker, the PC is taken from the authenticated credential rather than from
 * anything the request claims, and the response carries only a cadence — no image
 * bytes are echoed back, because the staff view fetches frames through a separate
 * policy-gated route.
 */
class StoreScreenFrame extends Controller
{
    public function __invoke(Request $request, ScreenFrameService $frames): Response
    {
        abort_unless((bool) config('lab.monitoring.enabled'), 503, 'Live Screens is disabled.');

        /** @var PcClient $client */
        $client = $request->user('pc_client');

        $frames->store($client, $request->getContent());

        $at = now();
        $client->touchFrame($at);
        $client->touchSeen();

        // Announce, do not push. The payload is a pc_id and nothing more, so the
        // websocket carries no image data — the same rule the rest of this app's
        // broadcasts follow. The teacher then requests the frame over normal HTTP,
        // through the policy that decides whether they may see it.
        //
        // Wrapped because ShouldBroadcastNow is synchronous and throws when the
        // broadcaster is unreachable. Without this, a Reverb restart would answer
        // every frame upload with a 500: the image was already written and the
        // timestamps already updated, but the client would be told it had failed and
        // would back off. A notification that can take down the thing it notifies
        // about is a dependency in the wrong direction — the frame is the data, the
        // broadcast is a nudge to refetch it, and the nudge must be allowed to fail.
        try {
            ScreenFrameAvailable::dispatch($client->pc_id, $at);
        } catch (\Throwable $exception) {
            // Logged, not swallowed silently: a persistently failing broadcaster is
            // worth knowing about even though it does not stop frames being stored.
            report($exception);
        }

        return response()->noContent()
            ->header('X-Frame-Interval', (string) $frames->intervalSeconds());
    }
}
