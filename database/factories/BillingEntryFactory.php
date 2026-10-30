<?php

namespace Database\Factories;

use App\Enums\BillingEntryType;
use App\Models\BillingClient;
use App\Models\BillingEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BillingEntry>
 */
class BillingEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'type' => BillingEntryType::Breakdown,
            'billing_client_id' => BillingClient::factory(),
            'user_id' => User::factory(),
            'work_date' => '2026-10-05',
            'ticket' => 'T'.fake()->unique()->numerify('#######'),
            'is_alone' => true,
            'comment' => fake()->sentence(),
        ];
    }
}
