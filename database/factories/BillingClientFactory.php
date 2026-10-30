<?php

namespace Database\Factories;

use App\Enums\BillingFormType;
use App\Models\BillingClient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingClient>
 */
class BillingClientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'sheet_label' => mb_strtoupper(mb_substr($name, 0, 31)),
            'form_type' => BillingFormType::TypeRate,
            'has_breakdown' => true,
            'has_works' => false,
            'has_travel_fee' => false,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    public function form(BillingFormType $type): static
    {
        return $this->state(fn () => ['form_type' => $type, 'has_breakdown' => $type !== BillingFormType::DayRatePair, 'has_works' => $type === BillingFormType::DayRatePair]);
    }
}
