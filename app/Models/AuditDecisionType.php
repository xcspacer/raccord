<?php

namespace App\Models;

use App\Enums\AuditDecisionOutcome;
use App\Enums\AuditSubmissionKind;
use Database\Factories\AuditDecisionTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Lang;

/**
 * One decision of the design office catalogue. Built-in ones are known by their code and labelled by the
 * translations; others carry the label typed by a supervisor. The outcome says what the decision does to the box.
 * Never deleted: switched off instead.
 *
 * @property int $id
 * @property string $code
 * @property string|null $label
 * @property AuditSubmissionKind $submission_kind
 * @property AuditDecisionOutcome $outcome
 * @property bool $requires_reason
 * @property bool $requires_targets
 * @property bool $is_active
 * @property int $position
 */
#[Fillable(['code', 'label', 'submission_kind', 'outcome', 'requires_reason', 'requires_targets', 'is_active', 'position'])]
class AuditDecisionType extends Model
{
    /** @use HasFactory<AuditDecisionTypeFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['submission_kind' => AuditSubmissionKind::class, 'outcome' => AuditDecisionOutcome::class, 'requires_reason' => 'boolean', 'requires_targets' => 'boolean', 'is_active' => 'boolean', 'position' => 'integer'];
    }

    /**
     * Label shown on screens: the typed one, else the translation of the built-in code.
     */
    public function displayLabel(): string
    {
        if (filled($this->label)) {
            return (string) $this->label;
        }

        $key = 'design_office_workflow.decision_types.'.$this->code;

        return Lang::has($key) ? __($key) : $this->outcome->label();
    }
}
