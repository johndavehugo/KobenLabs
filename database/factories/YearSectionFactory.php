<?php

namespace Database\Factories;

use App\Models\YearSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<YearSection>
 */
class YearSectionFactory extends Factory
{
    /**
     * Monotonic counter guaranteeing unique (year, section) pairs. A plain
     * random draw collides quickly once the table has a unique index.
     */
    protected static int $sequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $index = static::$sequence++;
        $year = 1 + intdiv($index, 3);
        $section = ['A', 'B', 'C'][$index % 3];

        return [
            'year' => "BSIT {$year}",
            'section' => $section,
            'is_active' => true,
        ];
    }

    /** A year with no section assigned yet. */
    public function yearOnly(): static
    {
        return $this->state(fn (array $attributes) => [
            'section' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
