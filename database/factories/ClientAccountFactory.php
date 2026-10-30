<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientAccount>
 */
class ClientAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'name' => fake()->name(),
            'username' => fake()->unique()->userName(),
            'password' => 'portal-password',
            'has_all_audits' => true,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
