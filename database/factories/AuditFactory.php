<?php

namespace Database\Factories;

use App\Enums\AuditWorkflow;
use App\Models\Audit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Audit>
 */
class AuditFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $key = 'PM'.fake()->unique()->numberBetween(100, 99999);

        return ['cabinet_key' => $key, 'folder' => $key, 'revision' => 1, 'workflow' => AuditWorkflow::Standard];
    }

    /**
     * An audit whose boxes are validated one by one by the design office.
     */
    public function designOffice(): static
    {
        return $this->state(['workflow' => AuditWorkflow::DesignOffice]);
    }
}
