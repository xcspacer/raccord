<?php

namespace Database\Factories;

use App\Enums\AuditReserveKind;
use App\Models\AuditBox;
use App\Models\AuditReserve;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditReserve>
 */
class AuditReserveFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['audit_box_id' => AuditBox::factory(), 'kind' => AuditReserveKind::FollowUp, 'reason' => fake()->sentence(), 'accepted_by' => User::factory(), 'accepted_at' => now()];
    }
}
