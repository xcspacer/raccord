<?php

namespace Database\Factories;

use App\Enums\HolidayRule;
use App\Models\PublicHoliday;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PublicHoliday>
 */
class PublicHolidayFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'rule' => HolidayRule::FixedDate,
            'month' => fake()->numberBetween(1, 12),
            'day' => fake()->numberBetween(1, 28),
        ];
    }

    public function easter(int $offset): static
    {
        return $this->state(['rule' => HolidayRule::EasterOffset, 'month' => null, 'day' => null, 'easter_offset' => $offset]);
    }
}
