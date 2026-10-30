<?php

namespace Database\Factories;

use App\Enums\AuditBoxMessageKind;
use App\Models\AuditBox;
use App\Models\AuditBoxMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditBoxMessage>
 */
class AuditBoxMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['audit_box_id' => AuditBox::factory(), 'author_id' => User::factory(), 'kind' => AuditBoxMessageKind::Chat, 'body' => fake()->sentence(), 'is_blocking' => false];
    }

    public function blocking(): static
    {
        return $this->state(['kind' => AuditBoxMessageKind::Observation, 'is_blocking' => true]);
    }
}
