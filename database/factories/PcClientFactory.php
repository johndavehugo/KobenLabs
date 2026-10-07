<?php

namespace Database\Factories;

use App\Enums\PcClientState;
use App\Models\Pc;
use App\Models\PcClient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PcClient>
 */
class PcClientFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pc_id' => Pc::factory(),
            // Hash of a throwaway token. The plaintext is never stored, so a factory
            // row cannot produce a working credential — tests that need one mint it
            // with $client->issueToken().
            'token_hash' => PcClient::hashToken(fake()->bothify('?????#####??????????')),
            'state' => PcClientState::Active,
            'hostname' => fake()->slug(2).'-lab',
            'client_version' => '1.0.0',
            'last_seen_at' => now(),
            'last_frame_at' => null,
            'enrolled_at' => now(),
            'revoked_at' => null,
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => [
            'state' => PcClientState::Revoked,
            'revoked_at' => now(),
        ]);
    }

    /** Never checked in, which is how an enrolment looks immediately after minting. */
    public function neverSeen(): static
    {
        return $this->state(fn (array $attributes) => [
            'last_seen_at' => null,
        ]);
    }

    /** Running, but silent past the threshold. */
    public function silent(): static
    {
        return $this->state(fn (array $attributes) => [
            'last_seen_at' => now()->subSeconds((int) config('lab.monitoring.client_silent_after_seconds') + 30),
        ]);
    }

    /** Uploading frames within the freshness window. */
    public function withFreshFrame(): static
    {
        return $this->state(fn (array $attributes) => [
            'last_seen_at' => now(),
            'last_frame_at' => now(),
        ]);
    }

    /** Has a frame on disk, but it is too old to display. */
    public function withStaleFrame(): static
    {
        return $this->state(fn (array $attributes) => [
            'last_seen_at' => now(),
            'last_frame_at' => now()->subSeconds((int) config('lab.monitoring.frame_stale_after_seconds') + 30),
        ]);
    }
}
