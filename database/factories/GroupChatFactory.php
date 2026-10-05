<?php

namespace Database\Factories;

use App\Enums\GroupRole;
use App\Models\GroupChat;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroupChat>
 */
class GroupChatFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->catchPhrase(),
            'description' => fake()->sentence(),
            'join_code' => GroupChat::generateJoinCode(),
            'created_by_id' => User::factory()->teacher(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * A group with its creator enrolled as an admin, matching the real
     * creation flow.
     */
    public function withMembers(?User $creator = null): static
    {
        return $this->afterCreating(function (GroupChat $group) use ($creator) {
            $group->join($creator ?? $group->creator, GroupRole::Admin);
        });
    }
}
