<?php

namespace Database\Factories;

use App\Enums\PcSessionStatus;
use App\Models\Pc;
use App\Models\PcSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PcSession>
 */
class PcSessionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->student(),
            'pc_id' => Pc::factory(),
            'started_at' => now(),
            'ended_at' => null,
            'duration_minutes' => null,
            'status' => PcSessionStatus::Active,
            'terminated_by_id' => null,
            'termination_reason' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(function (array $attributes) {
            $endedAt = now();

            return [
                'status' => PcSessionStatus::Completed,
                'ended_at' => $endedAt,
                'duration_minutes' => fake()->numberBetween(5, 480),
            ];
        });
    }

    public function terminated(?User $by = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PcSessionStatus::Terminated,
            'ended_at' => now(),
            'duration_minutes' => fake()->numberBetween(1, 120),
            'terminated_by_id' => $by?->id ?? User::factory()->admin(),
            'termination_reason' => fake()->sentence(),
        ]);
    }
}
