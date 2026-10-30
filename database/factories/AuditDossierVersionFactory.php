<?php

namespace Database\Factories;

use App\Enums\AuditDossierKind;
use App\Enums\AuditDossierStatus;
use App\Models\Audit;
use App\Models\AuditDossierVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditDossierVersion>
 */
class AuditDossierVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['audit_id' => Audit::factory()->designOffice(), 'version_number' => 1, 'kind' => AuditDossierKind::Initial, 'status' => AuditDossierStatus::Pending];
    }
}
