<?php

namespace App\Models;

use App\Enums\AuditAnomalyNature;
use Database\Factories\AuditAnomalyTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Lang;

/**
 * One anomaly type of the catalogue kept by the supervisors. Its nature never changes once created and it is never
 * deleted (switched off instead), so occurrences keep pointing to it. Built-in types are labelled by the translations.
 *
 * @property int $id
 * @property string|null $code
 * @property string|null $label
 * @property AuditAnomalyNature $nature
 * @property bool $is_active
 * @property int $position
 */
#[Fillable(['code', 'label', 'nature', 'is_active', 'position'])]
class AuditAnomalyType extends Model
{
    /** @use HasFactory<AuditAnomalyTypeFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['nature' => AuditAnomalyNature::class, 'is_active' => 'boolean', 'position' => 'integer'];
    }

    public function displayLabel(): string
    {
        if (filled($this->label)) {
            return (string) $this->label;
        }

        $key = 'design_office_workflow.anomaly_types.'.$this->code;

        return $this->code !== null && Lang::has($key) ? __($key) : (string) $this->code;
    }
}
