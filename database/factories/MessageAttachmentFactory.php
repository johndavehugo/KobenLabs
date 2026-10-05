<?php

namespace Database\Factories;

use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MessageAttachment>
 */
class MessageAttachmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::random(20).'.pdf';

        return [
            'message_id' => Message::factory(),
            'original_name' => $name,
            'path' => 'messages/1/'.Str::uuid7().'.pdf',
            'size' => fake()->numberBetween(1024, 5 * 1024 * 1024),
            'mime_type' => 'application/pdf',
        ];
    }
}
