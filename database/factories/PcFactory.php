<?php

namespace Database\Factories;

use App\Enums\PcStatus;
use App\Models\Pc;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pc>
 */
class PcFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Capped at 254: a /24 host octet cannot exceed 255, and the form
        // validates with ->ip(), so a wider range would generate addresses that
        // fail their own validation rule.
        $number = fake()->unique()->numberBetween(1, 254);

        return [
            'name' => "Lab Computer {$number}",
            'ip_address' => "192.168.1.{$number}",
            'mac_address' => null,
            'status' => PcStatus::Offline,
            'last_seen_at' => null,
        ];
    }

    public function online(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PcStatus::Online,
            'last_seen_at' => now(),
        ]);
    }

    public function maintenance(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PcStatus::Maintenance,
            'last_seen_at' => null,
        ]);
    }

    /** Online but idle past the stale threshold. */
    public function stale(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PcStatus::Online,
            'last_seen_at' => now()->subMinutes((int) config('lab.pcs.stale_after_minutes') + 5),
        ]);
    }

    public function withMac(): static
    {
        return $this->state(fn (array $attributes) => [
            'mac_address' => strtoupper(implode(':', [
                fake()->hexDigit(2),
                fake()->hexDigit(2),
                fake()->hexDigit(2),
                fake()->hexDigit(2),
                fake()->hexDigit(2),
                fake()->hexDigit(2),
            ])),
        ]);
    }
}
