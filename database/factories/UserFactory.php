<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Models\YearSection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $firstName = fake()->firstName();
        $lastName = fake()->lastName();

        return [
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'student_id' => null,
            'year_section_id' => null,
            'role' => UserRole::Student,
            'status' => UserStatus::Active,
            'storage_quota_mb' => config('lab.storage.student_quota_mb'),
            'storage_used_mb' => 0,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Admin,
            'student_id' => null,
            'storage_quota_mb' => config('lab.storage.admin_quota_mb'),
        ]);
    }

    public function teacher(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Teacher,
            'student_id' => null,
            'storage_quota_mb' => config('lab.storage.teacher_quota_mb'),
        ]);
    }

    public function student(?YearSection $yearSection = null): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Student,
            'student_id' => 'STU'.fake()->unique()->numerify('#######'),
            'year_section_id' => $yearSection?->id ?? YearSection::factory(),
            'storage_quota_mb' => config('lab.storage.student_quota_mb'),
        ]);
    }

    public function staff(): static
    {
        return $this->teacher();
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => UserStatus::Inactive,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => UserStatus::Suspended,
        ]);
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
