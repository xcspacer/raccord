<?php

namespace Database\Factories;

use App\Enums\AuditInterventionStatus;
use App\Models\AuditBox;
use App\Models\AuditIntervention;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditIntervention>
 */
class AuditInterventionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'audit_box_id' => AuditBox::factory(),
            'audit_id' => fn (array $attributes) => AuditBox::query()->whereKey($attributes['audit_box_id'])->value('audit_id'),
            'planned_on' => now()->addDay()->toDateString(),
            'responsible_id' => User::factory(),
            'status' => AuditInterventionStatus::Planned,
        ];
    }
}
