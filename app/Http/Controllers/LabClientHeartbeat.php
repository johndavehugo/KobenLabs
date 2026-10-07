<?php

namespace App\Http\Controllers;

use App\Models\PcClient;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Liveness check for the Lab Client.
 *
 * Separate from posting a frame because capture stops in situations where the machine
 * is fine. Windows reports a session lock, and a locked desktop either yields a black
 * frame or a lock-screen image — legacy streamed those at full rate, so a locked PC
 * was indistinguishable from a working one on a teacher's grid.
 *
 * The client uses this to report `locked` without uploading anything, so the staff
 * view can label the machine honestly instead of showing a frozen thumbnail.
 */
class LabClientHeartbeat extends Controller
{
    public function __invoke(Request $request): Response
    {
        /** @var PcClient $client */
        $client = $request->user('pc_client');

        $state = (string) $request->input('state', 'capturing');

        // Free text from an untrusted-ish source, so keep it to a known set rather
        // than storing whatever arrives.
        $allowed = ['capturing', 'locked', 'idle'];

        $client->forceFill([
            'last_seen_at' => now(),
            'hostname' => mb_substr((string) $request->input('hostname', $client->hostname), 0, 255),
            'client_version' => mb_substr((string) $request->input('version', $client->client_version), 0, 20),
        ]);

        if (in_array($state, $allowed, true) && $state !== 'capturing') {
            // Locked and idle mean "deliberately not uploading", so the stored frame
            // is no longer current and the grid must stop showing it.
            $client->forceFill(['last_frame_at' => null]);
        }

        $client->saveQuietly();

        return response()->noContent()
            ->header('X-Frame-Interval', (string) max(0.2, (float) config('lab.monitoring.interval_seconds')));
    }
}
