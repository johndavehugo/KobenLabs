<?php

namespace Database\Factories;

use App\Enums\ScheduleStatus;
use App\Models\Schedule;
use App\Models\User;
use App\Models\YearSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Schedule>
 */
class ScheduleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = fake()->dateTimeBetween('+1 day', '+2 weeks');

        return [
            'teacher_id' => User::factory()->teacher(),
            'year_section_id' => YearSection::factory(),
            'title' => fake()->randomElement([
                'Introduction to Programming',
                'Web Development',
                'Data Structures',
                'Database Systems',
                'Networks',
            ]),
            'description' => fake()->sentence(),
            'starts_at' => $startsAt,
            'ends_at' => (clone $startsAt)->modify('+2 hours'),
            'purpose' => 'Lab session',
            'status' => ScheduleStatus::Scheduled,
        ];
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ScheduleStatus::Cancelled,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ScheduleStatus::Completed,
        ]);
    }

    public function scheduledFor(User $teacher, YearSection $section, string $startsAt, string $endsAt): static
    {
        return $this->state(fn (array $attributes) => [
            'teacher_id' => $teacher->id,
            'year_section_id' => $section->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);
    }
}
