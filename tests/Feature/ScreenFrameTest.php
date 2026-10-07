<?php

use App\Events\ScreenFrameAvailable;
use App\Models\PcClient;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

/**
|--------------------------------------------------------------------------
| Storing screen frames
|--------------------------------------------------------------------------
|
| A frame arrives as a raw request body from a machine, so it gets none of the
| FileService treatment: no filename, no extension, no quota, and no place in a user's
| drive. What it does get is a validation that trusts nothing the client said about
| itself — the legacy relay accepted a base64 blob on a socket and streamed it to
| every teacher without ever looking at it.
 */
describe('storing a screen frame', function (): void {
    beforeEach(function (): void {
        Storage::fake();
    });

    it('accepts a JPEG and returns the capture interval', function (): void {
        $client = PcClient::factory()->create();
        $token = $client->issueToken();

        $response = $this->withToken($token)
            ->call('POST', '/api/client/frame', [], [], [], [
                'CONTENT_TYPE' => 'image/jpeg',
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            ], jpegBytes());

        $response->assertStatus(204);

        // The server hands back the cadence, so config/lab.php is the single place the
        // rate is decided rather than something set per machine.
        expect($response->headers->get('X-Frame-Interval'))
            ->toBe((string) (float) config('lab.monitoring.interval_seconds'));

        Storage::disk(config('lab.storage.default_disk'))
            ->assertExists("monitoring/pcs/{$client->pc_id}/latest.jpg");
    });

    it('rejects a body that is not a JPEG, whatever the content type claims', function (): void {
        $client = PcClient::factory()->create();
        $token = $client->issueToken();

        $this->withToken($token)
            ->call('POST', '/api/client/frame', [], [], [], [
                'CONTENT_TYPE' => 'image/jpeg',
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            ], '<?php system($_GET["c"]); ?>')
            ->assertStatus(422);

        Storage::disk(config('lab.storage.default_disk'))
            ->assertMissing("monitoring/pcs/{$client->pc_id}/latest.jpg");
    });

    it('rejects an oversized frame', function (): void {
        config()->set('lab.monitoring.frame_max_kilobytes', 1);

        $client = PcClient::factory()->create();
        $token = $client->issueToken();

        // Padded past the ceiling but still JPEG-shaped, so the size rule is what is
        // refused rather than the magic bytes.
        $oversized = jpegBytes().str_repeat('0', 4096);

        $this->withToken($token)
            ->call('POST', '/api/client/frame', [], [], [], [
                'CONTENT_TYPE' => 'image/jpeg',
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            ], $oversized)
            ->assertStatus(422);

        Storage::disk(config('lab.storage.default_disk'))
            ->assertMissing("monitoring/pcs/{$client->pc_id}/latest.jpg");
    });

    it('overwrites rather than accumulating, so nothing builds up on disk', function (): void {
        $client = PcClient::factory()->create();
        $token = $client->issueToken();

        foreach (range(1, 5) as $_) {
            $this->withToken($token)
                ->call('POST', '/api/client/frame', [], [], [], [
                    'CONTENT_TYPE' => 'image/jpeg',
                    'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                ], jpegBytes())
                ->assertStatus(204);
        }

        $disk = Storage::disk(config('lab.storage.default_disk'));

        // One file, not five. There is no replay feature, so a history would be pure
        // disk cost and a wider privacy surface.
        expect($disk->allFiles('monitoring'))->toHaveCount(1);
    });

    it('records when the frame arrived, so a stale one can be detected', function (): void {
        $client = PcClient::factory()->create();
        $token = $client->issueToken();

        expect($client->last_frame_at)->toBeNull();

        $this->withToken($token)
            ->call('POST', '/api/client/frame', [], [], [], [
                'CONTENT_TYPE' => 'image/jpeg',
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            ], jpegBytes())
            ->assertStatus(204);

        expect($client->fresh()->last_frame_at)->not->toBeNull()
            ->and($client->fresh()->hasFreshFrame())->toBeTrue();
    });

    it('announces the PC id and never the image', function (): void {
        Event::fake([ScreenFrameAvailable::class]);

        $client = PcClient::factory()->create();
        $token = $client->issueToken();

        $this->withToken($token)
            ->call('POST', '/api/client/frame', [], [], [], [
                'CONTENT_TYPE' => 'image/jpeg',
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            ], jpegBytes())
            ->assertStatus(204);

        Event::assertDispatched(ScreenFrameAvailable::class, function (ScreenFrameAvailable $event) use ($client): bool {
            // The payload keys are the whole contract. If image bytes ever appear here
            // the websocket becomes a media channel, and the bandwidth problem legacy
            // had comes straight back. Asserted on the key *set*, not the values, so a
            // new field cannot be added without this failing.
            return $event->pcId === $client->pc_id
                && array_keys($event->broadcastWith()) === ['pc_id', 'at'];
        });

        // Belt and braces: nothing base64-shaped anywhere in the broadcast.
        $dispatched = Event::dispatched(ScreenFrameAvailable::class)->first()[0];

        expect(json_encode($dispatched->broadcastWith()))
            ->not->toContain('base64')
            ->not->toContain('/9j/');
    });

    it('stores the frame even when the broadcaster is unreachable', function (): void {
        Event::fake([ScreenFrameAvailable::class]);

        // Make dispatching throw, the way it does when Reverb is down: the event is
        // ShouldBroadcastNow, so a failed connection propagates out of dispatch().
        Event::listen(ScreenFrameAvailable::class, function (): void {
            throw new RuntimeException('broadcaster unreachable');
        });

        $client = PcClient::factory()->create();
        $token = $client->issueToken();

        $this->withToken($token)
            ->call('POST', '/api/client/frame', [], [], [], [
                'CONTENT_TYPE' => 'image/jpeg',
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            ], jpegBytes())
            ->assertStatus(204);

        // The image is the data; the broadcast is only a nudge to refetch it. A
        // Reverb restart must not answer a frame upload with a 500 and convince the
        // client it had failed.
        Storage::disk(config('lab.storage.default_disk'))
            ->assertExists("monitoring/pcs/{$client->pc_id}/latest.jpg");

        expect($client->fresh()->last_frame_at)->not->toBeNull();
    });

    it('keeps one PC out of another PC slot', function (): void {
        $first = PcClient::factory()->create();
        $second = PcClient::factory()->create();

        $this->withToken($first->issueToken())
            ->call('POST', '/api/client/frame', [], [], [], [
                'CONTENT_TYPE' => 'image/jpeg',
                'HTTP_AUTHORIZATION' => 'Bearer '.$first->issueToken(),
            ], jpegBytes())
            ->assertStatus(204);

        $disk = Storage::disk(config('lab.storage.default_disk'));

        expect($disk->exists("monitoring/pcs/{$first->pc_id}/latest.jpg"))->toBeTrue()
            ->and($disk->exists("monitoring/pcs/{$second->pc_id}/latest.jpg"))->toBeFalse();
    });
});

describe('heartbeats', function (): void {
    beforeEach(function (): void {
        Storage::fake();
    });

    it('marks a locked machine so the grid can stop showing a frozen frame', function (): void {
        $client = PcClient::factory()->withFreshFrame()->create();
        $token = $client->issueToken();

        expect($client->hasFreshFrame())->toBeTrue();

        // Windows reports a session lock, and a locked desktop yields either a black
        // frame or the lock screen. Legacy streamed those at full rate, so a locked PC
        // was indistinguishable from a working one.
        $this->withToken($token)
            ->postJson('/api/client/heartbeat', ['state' => 'locked'])
            ->assertStatus(204);

        expect($client->fresh()->hasFreshFrame())->toBeFalse();
    });

    it('leaves the frame current for a machine that is still capturing', function (): void {
        $client = PcClient::factory()->withFreshFrame()->create();

        $this->withToken($client->issueToken())
            ->postJson('/api/client/heartbeat', ['state' => 'capturing'])
            ->assertStatus(204);

        expect($client->fresh()->hasFreshFrame())->toBeTrue();
    });

    it('refuses to store an arbitrary state string from the wire', function (): void {
        $client = PcClient::factory()->withFreshFrame()->create();

        $this->withToken($client->issueToken())
            ->postJson('/api/client/heartbeat', ['state' => 'anything at all'])
            ->assertStatus(204);

        // Unrecognised states are treated as ordinary captures rather than being
        // written to the row.
        expect($client->fresh()->hasFreshFrame())->toBeTrue();
    });
});
