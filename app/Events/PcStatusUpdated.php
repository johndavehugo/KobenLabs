<?php

namespace App\Events;

use App\Enums\PcStatus;
use App\Models\Pc;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A lab PC changed status, so any open panel showing the inventory should
 * update the affected card.
 *
 * `ShouldBroadcastNow` rather than `ShouldBroadcast` so delivery does not depend
 * on a queue worker being running — this fires inside a login request. With
 * `BROADCAST_CONNECTION=log` the payload is written to the log until Phase 5
 * swaps in Reverb, at which point no code here changes.
 */
class PcStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $pcId,
        public readonly string $pcName,
        public readonly PcStatus $status,
    ) {}

    /**
     * Staff-only channel. The legacy WebSocket fanned PC status out to every
     * connected teacher socket with no authentication at all, so anyone could
     * register as a teacher and watch the lab; see README §7 for the migration
     * note. Channel authorization lives in routes/channels.php.
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('pcs')];
    }

    public function broadcastAs(): string
    {
        return 'pc.status.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'pc_id' => $this->pcId,
            'pc_name' => $this->pcName,
            'status' => $this->status->value,
        ];
    }
}
