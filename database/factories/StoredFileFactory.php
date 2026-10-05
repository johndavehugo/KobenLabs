<?php

namespace Database\Factories;

use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StoredFile>
 */
class StoredFileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::random(20).'.txt';

        return [
            'user_id' => User::factory()->student(),
            'name' => $name,
            'original_name' => $name,
            'path' => 'users/1/'.Str::uuid7().'.txt',
            'size' => fake()->numberBetween(1024, 5 * 1024 * 1024),
            'mime_type' => 'text/plain',
            'parent_id' => null,
            'is_folder' => false,
        ];
    }

    public function folder(?StoredFile $parent = null): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => fake()->unique()->word(),
            'original_name' => $attributes['name'],
            'path' => '',
            'size' => 0,
            'mime_type' => 'folder',
            'parent_id' => $parent?->id,
            'is_folder' => true,
        ]);
    }

    public function inside(StoredFile $folder): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $folder->user_id,
            'parent_id' => $folder->id,
        ]);
    }

    public function image(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => Str::random(20).'.png',
            'original_name' => Str::random(20).'.png',
            'path' => 'users/1/'.Str::uuid7().'.png',
            'mime_type' => 'image/png',
        ]);
    }
}
