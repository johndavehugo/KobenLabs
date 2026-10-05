<?php

namespace Database\Factories;

use App\Enums\ClassSessionStatus;
use App\Models\ClassSession;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassSession>
 */
class ClassSessionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    /**
     * Only one active session may exist, so this state is the default rather
     * than leaving `status` to the database.
     */
    public function definition(): array
    {
        return [
            'schedule_id' => Schedule::factory(),
            'started_by_id' => User::factory()->teacher(),
            'started_at' => now(),
            'ended_at' => null,
            'status' => ClassSessionStatus::Active,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ClassSessionStatus::Completed,
            'ended_at' => now()->addHours(2),
        ]);
    }
}
