<?php

namespace Database\Factories;

use App\Enums\WorkSiteStatus;
use App\Models\Agency;
use App\Models\Client;
use App\Models\WorkSite;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkSite>
 */
class WorkSiteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsOn = fake()->dateTimeBetween('-2 weeks', '+4 weeks');

        return [
            'agency_id' => Agency::factory(),
            'client_id' => Client::factory(),
            'city' => fake()->city(),
            'starts_on' => $startsOn,
            'ends_on' => (clone $startsOn)->modify('+'.fake()->numberBetween(0, 10).' days'),
            'crew_count' => fake()->numberBetween(1, 3),
            'status' => fake()->randomElement(WorkSiteStatus::cases()),
        ];
    }
}
