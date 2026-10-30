<?php

namespace Database\Factories;

use App\Enums\AuditAnomalyNature;
use App\Enums\AuditOccurrenceSource;
use App\Enums\AuditOccurrenceState;
use App\Models\AuditAnomalyType;
use App\Models\AuditBox;
use App\Models\AuditOccurrence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditOccurrence>
 */
class AuditOccurrenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sequence = fake()->unique()->numberBetween(1, 999999);

        return [
            'audit_box_id' => AuditBox::factory(),
            'audit_id' => fn (array $attributes) => AuditBox::query()->whereKey($attributes['audit_box_id'])->value('audit_id'),
            'sequence' => $sequence,
            'reference' => 'OC-TEST-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
            'audit_anomaly_type_id' => AuditAnomalyType::factory(),
            'nature' => AuditAnomalyNature::Mechanical,
            'state' => AuditOccurrenceState::Detected,
            'source' => AuditOccurrenceSource::Field,
            'description' => fake()->sentence(),
        ];
    }

    public function optical(): static
    {
        return $this->state(['nature' => AuditAnomalyNature::Optical, 'audit_anomaly_type_id' => AuditAnomalyType::factory()->optical()]);
    }
}
