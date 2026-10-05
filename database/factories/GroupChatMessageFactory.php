<?php

namespace Database\Factories;

use App\Enums\GroupMessageType;
use App\Models\GroupChat;
use App\Models\GroupChatMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroupChatMessage>
 */
class GroupChatMessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'group_chat_id' => GroupChat::factory(),
            'sender_id' => User::factory()->student(),
            'body' => fake()->sentence(),
            'type' => GroupMessageType::Text,
        ];
    }

    /** Join and leave notifications carry a null sender. */
    public function system(): static
    {
        return $this->state(fn (array $attributes) => [
            'sender_id' => null,
            'type' => GroupMessageType::System,
            'body' => fake()->sentence(),
        ]);
    }

    public function file(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => GroupMessageType::File,
            'file_path' => 'group-chats/1/document.pdf',
            'file_name' => 'document.pdf',
            'file_size' => fake()->numberBetween(1024, 10 * 1024 * 1024),
        ]);
    }

    public function replyingTo(GroupChatMessage $message): static
    {
        return $this->state(fn (array $attributes) => [
            'group_chat_id' => $message->group_chat_id,
            'reply_to_id' => $message->id,
        ]);
    }
}
