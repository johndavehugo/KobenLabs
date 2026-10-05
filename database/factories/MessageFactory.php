<?php

namespace Database\Factories;

use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sender_id' => User::factory()->teacher(),
            'subject' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'is_broadcast' => false,
            'parent_id' => null,
        ];
    }

    public function broadcast(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_broadcast' => true,
        ]);
    }

    public function replyTo(Message $message): static
    {
        return $this->state(fn (array $attributes) => [
            'parent_id' => $message->id,
        ]);
    }
}
