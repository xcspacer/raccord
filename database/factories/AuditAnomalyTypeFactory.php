<?php

namespace Database\Factories;

use App\Enums\AuditAnomalyNature;
use App\Models\AuditAnomalyType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditAnomalyType>
 */
class AuditAnomalyTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['code' => null, 'label' => fake()->words(3, true), 'nature' => AuditAnomalyNature::Mechanical, 'is_active' => true, 'position' => 100];
    }

    public function optical(): static
    {
        return $this->state(['nature' => AuditAnomalyNature::Optical]);
    }
}
