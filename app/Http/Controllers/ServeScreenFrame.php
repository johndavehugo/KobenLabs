<?php

namespace App\Http\Controllers;

use App\Models\Pc;
use App\Models\PcClient;
use App\Services\ScreenFrameService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Serve one lab PC's current screen frame to staff.
 *
 * The single route through which any screen bytes leave the server, so it is also the
 * single place access is decided. Gate::authorize is the first statement, before the
 * frame is located or its existence is revealed — otherwise the response code alone
 * would tell a teacher whether an arbitrary PC id exists in the lab.
 *
 * One PC per request rather than a batch endpoint. That looks less efficient and is
 * the reason this is safe: a batch route would have to filter its own result set,
 * and a filtering bug becomes an over-share. Here a wrong id is simply refused, and
 * the request rate is bounded by what the teacher is actually looking at.
 */
class ServeScreenFrame extends Controller
{
    public function __invoke(Request $request, Pc $pc, ScreenFrameService $frames): Response
    {
        Gate::authorize('viewScreen', $pc);

        $client = $pc->client;

        // A revoked enrolment means the machine is decommissioned. Its last frame
        // is not a live view of anything and should not be renderable.
        if (! $client instanceof PcClient || ! $client->isActive()) {
            abort(404, 'No Lab Client is enrolled for that machine.');
        }

        // Both halves matter: the file being present says a frame was captured, the
        // timestamp says it is current. Serving the file alone would leave a frozen
        // image on the grid reading as live, which is precisely how legacy made a
        // dead machine indistinguishable from a working one.
        abort_unless($frames->hasDisplayableFrame($client), 404, 'That machine has no current frame.');

        $bytes = $frames->read($client);
        abort_if($bytes === null, 404, 'That machine has no current frame.');

        $fingerprint = $frames->fingerprint($client);

        // Last-Modified based conditional, so a tile that re-requests the same frame
        // costs a 304 with no body rather than ~100 KB of image.
        if ($fingerprint !== null && $request->header('If-Modified-Since') === $fingerprint) {
            return response('', 304);
        }

        return response($bytes, 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Length' => (string) strlen($bytes),

            // Never cached beyond its own freshness window. A shared or back-button
            // cache holding yesterday's screen is a privacy problem, not just a
            // performance one.
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ] + ($fingerprint === null ? [] : ['Last-Modified' => $fingerprint]));
    }
}
