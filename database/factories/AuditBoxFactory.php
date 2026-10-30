<?php

namespace Database\Factories;

use App\Enums\AuditBoxKind;
use App\Enums\AuditBoxSource;
use App\Enums\AuditSupport;
use App\Models\Audit;
use App\Models\AuditBox;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditBox>
 */
class AuditBoxFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'audit_id' => Audit::factory(),
            'name' => 'BPE-'.fake()->unique()->numberBetween(1000, 999999),
            'kind' => AuditBoxKind::Bpe,
            'support' => AuditSupport::Chamber,
            'source' => AuditBoxSource::Manager,
        ];
    }
}
