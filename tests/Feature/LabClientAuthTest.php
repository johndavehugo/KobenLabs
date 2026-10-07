<?php

use App\Models\AuditLog;
use App\Models\Pc;
use App\Models\PcClient;

/**
|--------------------------------------------------------------------------
| Lab Client credentials
|--------------------------------------------------------------------------
|
| The Lab Client is the background service on a lab PC. Legacy had no
| authentication for it at all: its relay accepted an `agent_register` message from
| anything that connected, trusting a self-reported IP, which meant any host on the
| lab network could inject a fake machine's screen into a teacher's grid.
|
| These tests pin the parts of that fix which are invisible when working — a token
| that is stored in plaintext, or one that keeps working after revocation, would
| both pass every feature test elsewhere in the suite.
 */
describe('Lab Client credentials', function (): void {
    it('never stores the token, only its hash', function (): void {
        $client = PcClient::factory()->create();

        $token = $client->issueToken();

        expect($client->fresh()->token_hash)->toBe(PcClient::hashToken($token))
            ->and($client->fresh()->token_hash)->not->toBe($token)
            // A SHA-256 hex digest is a fixed 64 characters, which is also what the
            // column is sized for.
            ->and($client->fresh()->token_hash)->toHaveLength(64);
    });

    it('resolves a valid token to its client', function (): void {
        $client = PcClient::factory()->create();
        $token = $client->issueToken();

        expect(PcClient::findByToken($token)?->is($client))->toBeTrue();
    });

    it('refuses an unknown or empty token', function (): void {
        PcClient::factory()->create();

        expect(PcClient::findByToken('not-a-real-token'))->toBeNull()
            ->and(PcClient::findByToken(''))->toBeNull()
            ->and(PcClient::findByToken(null))->toBeNull();
    });

    it('refuses a revoked token even though the hash is still on file', function (): void {
        $client = PcClient::factory()->create();
        $token = $client->issueToken();

        expect(PcClient::findByToken($token))->not->toBeNull();

        $client->revoke();

        expect($client->fresh()->state->isActive())->toBeFalse()
            ->and(PcClient::findByToken($token))->toBeNull();
    });

    it('replaces the credential when a token is issued again', function (): void {
        $client = PcClient::factory()->create();

        $first = $client->issueToken();
        $second = $client->issueToken();

        expect($second)->not->toBe($first)
            ->and(PcClient::findByToken($first))->toBeNull()
            ->and(PcClient::findByToken($second)?->is($client))->toBeTrue();
    });

    it('treats a client that never checked in as silent', function (): void {
        expect(PcClient::factory()->neverSeen()->create()->isSilent())->toBeTrue()
            ->and(PcClient::factory()->create()->isSilent())->toBeFalse();
    });

    it('keeps the credential digest out of the audit trail', function (): void {
        $client = PcClient::factory()->create();
        $token = $client->issueToken();

        // PcClient is in config('lab.audit.models'), so enrolling and rotating a
        // credential is audited. The audit trail is readable by staff, so the digest
        // must not land in it — a hash sitting in an export or a backup is a
        // credential that has to be rotated.
        $entries = AuditLog::query()
            ->where('auditable_type', PcClient::class)
            ->get();

        expect($entries)->not->toBeEmpty();

        foreach ($entries as $entry) {
            $serialised = json_encode($entry->properties);

            expect($serialised)
                ->not->toContain(PcClient::hashToken($token))
                ->not->toContain($token);
        }
    });

    it('redacts token_hash by its column name, not by a token substring match', function (): void {
        // Redaction is exact array_key_exists, not a substring match. Listing only
        // 'token' in the config would leave 'token_hash' in the trail and still look
        // correct in the config file.
        expect((array) config('lab.audit.redact'))
            ->toContain('token_hash');
    });

    it('honours a fresh-frame window rather than the PC occupancy one', function (): void {
        $client = PcClient::factory()->withFreshFrame()->create();

        expect($client->hasFreshFrame())->toBeTrue();

        $client->forceFill([
            'last_frame_at' => now()->subSeconds((int) config('lab.monitoring.frame_stale_after_seconds') + 30),
        ])->save();

        expect($client->fresh()->hasFreshFrame())->toBeFalse();
    });
});

describe('Lab Client HTTP authentication', function (): void {
    it('rejects a frame with no token', function (): void {
        $this->postJson('/api/client/frame', [], ['Content-Type' => 'image/jpeg'])
            ->assertUnauthorized();
    });

    it('rejects a frame with a bad token', function (): void {
        Pc::factory()->create();

        $this->withToken('nonsense')
            ->postJson('/api/client/frame', [], ['Content-Type' => 'image/jpeg'])
            ->assertUnauthorized();
    });

    it('rejects a frame from a revoked client', function (): void {
        $client = PcClient::factory()->revoked()->create();
        $token = $client->issueToken();

        $this->withToken($token)
            ->postJson('/api/client/frame', [], ['Content-Type' => 'image/jpeg'])
            ->assertUnauthorized();
    });

    it('answers a machine with JSON, not the sign-in page', function (): void {
        // The framework's guest redirect would otherwise answer a lab PC with an HTML
        // login form, and the client would have to parse a web page to learn it was
        // rejected. These routes sit under /api/ so shouldRenderJsonWhen() applies.
        $response = $this->postJson('/api/client/frame', [], ['Content-Type' => 'image/jpeg']);

        $response->assertUnauthorized();

        expect($response->getContent())
            ->toContain('message')
            ->not->toContain('<form');
    });

    it('serves config to an enrolled client without echoing the token back', function (): void {
        $pc = Pc::factory()->create(['name' => 'LAB-07']);
        $client = PcClient::factory()->for($pc)->create();
        $token = $client->issueToken();

        $response = $this->withToken($token)->getJson('/api/client/config');

        $response->assertOk()
            ->assertJsonPath('pc.name', 'LAB-07')
            ->assertJsonPath('enabled', true);

        // Returning the token would put it in every access log between the server and
        // the machine.
        expect($response->getContent())->not->toContain($token);
    });

    it('refuses every client while Live Screens is disabled', function (): void {
        config()->set('lab.monitoring.enabled', false);

        $client = PcClient::factory()->create();
        $token = $client->issueToken();

        $this->withToken($token)
            ->postJson('/api/client/frame', [], ['Content-Type' => 'image/jpeg'])
            ->assertStatus(503);
    });
});

/**
 * A 2x1 JPEG. Small, and genuinely a JPEG, so the magic-byte check passes and the
 * size check is not what is being exercised.
 */
function labClientTestJpeg(): string
{
    return base64_decode(
        '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRof'
        .'Hh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwh'
        .'MjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIy'
        .'MjL/wAARCAABAAEDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcI'
        .'CQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwR'
        .'VS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlq'
        .'c3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJyt'
        .'LT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/9oACAEBAAA/APn+iiigD//Z'
    );
}
