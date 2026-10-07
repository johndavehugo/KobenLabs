<?php

namespace App\Http\Controllers;

use App\Models\PcClient;
use App\Services\ScreenFrameService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tell the Lab Client how the server wants it to behave.
 *
 * The client polls this on start and whenever it is unsure, which is what makes the
 * capture rate and the on/off switch centrally controlled: changing
 * `config/lab.php` retunes every enrolled machine, and `enabled = false` turns the
 * feature off without uninstalling anything from the lab.
 *
 * No token is returned. The client already holds its credential; echoing it back
 * would put it in every access log on the path between here and the machine.
 */
class LabClientConfig extends Controller
{
    public function __invoke(Request $request, ScreenFrameService $frames): JsonResponse
    {
        /** @var PcClient $client */
        $client = $request->user('pc_client');

        $client->touchSeen();

        return response()->json([
            'enabled' => (bool) config('lab.monitoring.enabled'),
            'interval_seconds' => $frames->intervalSeconds(),
            'max_width' => (int) config('lab.monitoring.max_width'),
            'jpeg_quality' => (int) config('lab.monitoring.jpeg_quality'),
            'frame_max_kilobytes' => (int) config('lab.monitoring.frame_max_kilobytes'),

            'pc' => [
                'id' => $client->pc_id,
                'name' => $client->pc->name,
            ],
        ]);
    }
}
