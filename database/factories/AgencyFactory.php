<?php

namespace Database\Factories;

use App\Enums\PlanningMode;
use App\Models\Agency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Agency>
 */
class AgencyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->city(),
            'code' => substr(fake()->unique()->slug(2), 0, 28),
            'planning_mode' => PlanningMode::Detailed,
            'daily_crew_capacity' => 4,
        ];
    }
}
