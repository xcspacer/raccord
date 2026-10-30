<?php

namespace Database\Factories;

use App\Enums\AbsenceType;
use App\Models\Absence;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Absence>
 */
class AbsenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsOn = fake()->dateTimeBetween('-1 week', '+3 weeks');

        return [
            'user_id' => User::factory(),
            'type' => fake()->randomElement(AbsenceType::cases()),
            'starts_on' => $startsOn,
            'ends_on' => (clone $startsOn)->modify('+'.fake()->numberBetween(0, 4).' days'),
        ];
    }
}
