<?php

namespace Database\Factories;

use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'date' => fake()->dateTimeBetween('-1 week', '+2 weeks'),
            'state_code' => 'HO',
            'label' => fake()->randomElement(['SWAP', 'NRO', 'AUDIT']),
            'detail' => fake()->sentence(4),
        ];
    }
}
