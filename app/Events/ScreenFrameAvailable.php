<?php

namespace App\Events;

use App\Models\Pc;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A lab PC has a newer screen frame than the one its viewer is showing.
 *
 * Carries a PC id and nothing else. No image bytes, no base64, no resolution.
 *
 * This is the rule the rest of this app's broadcasts already follow — see
 * resources/js/echo.js — and it is load-bearing here rather than merely tidy. Legacy
 * pushed 20 fps of ~100 KB base64 frames *through* its websocket relay and then
 * fanned each frame out to every connected teacher. A 40-PC lab was 520-960 Mbps
 * inbound before fan-out, and the relay could not keep up.
 *
 * Instead the socket says "PC 7 has moved" and the browser fetches that one frame
 * over ordinary HTTP through PcPolicy::viewScreen. Three things fall out of that:
 * the teacher never receives bytes for a PC outside their class, because the request
 * is authorised on its own; the bandwidth is proportional to what the teacher
 * actually looks at rather than to what every PC is capturing; and Reverb stays a
 * signalling channel rather than becoming a media server.
 *
 * ShouldBroadcastNow rather than ShouldBroadcast, matching every other event here —
 * a frame that arrives after the teacher has moved on is worse than one that never
 * arrives, so delivery must not queue behind the database queue.
 */
class ScreenFrameAvailable implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly int $pcId,
        public readonly string $occurredAt,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        // Staff only, per routes/channels.php. Note this is *who may subscribe*, not
        // *what they may see* — a teacher subscribed here is still refused frames for
        // PCs outside their class at the point of fetching one.
        return [new PrivateChannel('monitoring')];
    }

    public function broadcastAs(): string
    {
        return 'screen-frame.available';
    }

    /**
     * @return array<string, int|string>
     */
    public function broadcastWith(): array
    {
        return [
            'pc_id' => $this->pcId,
            'at' => $this->occurredAt,
        ];
    }

    public static function forPc(Pc $pc, string $occurredAt): self
    {
        return new self((int) $pc->getKey(), $occurredAt);
    }
}
