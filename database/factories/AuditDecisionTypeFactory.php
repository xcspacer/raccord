<?php

namespace Database\Factories;

use App\Enums\AuditDecisionOutcome;
use App\Models\AuditDecisionType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditDecisionType>
 */
class AuditDecisionTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'custom_'.fake()->unique()->numberBetween(1, 999999),
            'label' => fake()->sentence(3),
            'submission_kind' => AuditDecisionOutcome::Refuse->submissionKind(),
            'outcome' => AuditDecisionOutcome::Refuse,
            'requires_reason' => true,
            'requires_targets' => false,
            'is_active' => true,
            'position' => 100,
        ];
    }
}
