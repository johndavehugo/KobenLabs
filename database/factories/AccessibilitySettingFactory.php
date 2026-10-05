<?php

namespace Database\Factories;

use App\Enums\TextSize;
use App\Models\AccessibilitySetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccessibilitySetting>
 */
class AccessibilitySettingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'text_size' => TextSize::Normal,
            'high_contrast' => false,
            'text_to_speech' => false,
        ];
    }

    public function largeText(): static
    {
        return $this->state(fn (array $attributes) => [
            'text_size' => TextSize::Large,
        ]);
    }

    public function highContrast(): static
    {
        return $this->state(fn (array $attributes) => [
            'high_contrast' => true,
        ]);
    }
}
