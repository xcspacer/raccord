<?php

namespace App\DesignOfficeWorkflow;

use App\Enums\AuditDecisionOutcome;
use App\Models\AuditDecisionType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The decisions the design office chooses from, kept by the supervisors. A new decision gets a label (at least 3
 * characters) and the effect it has on the box (one of the outcomes the workflow knows, fixed once created); the
 * supervisors say whether it needs a reason and the points to correct (always needed for a refusal). Decisions are
 * never deleted — switched off instead, never the last active one of an outcome, so the design office can always
 * validate, refuse and so on. A built-in decision keeps its translated label unless a supervisor types one.
 */
class DecisionCatalogue
{
    public const int MIN_LABEL = 3;

    public function __construct(private WorkflowJournal $journal) {}

    /**
     * @throws WorkflowException
     */
    public function create(User $supervisor, string $label, AuditDecisionOutcome $outcome, bool $requiresReason, bool $requiresTargets): AuditDecisionType
    {
        $this->ensureSupervisor($supervisor);
        $label = $this->label($label, required: true);

        return DB::transaction(function () use ($supervisor, $label, $outcome, $requiresReason, $requiresTargets): AuditDecisionType {
            $type = AuditDecisionType::query()->create([
                'code' => 'custom_'.Str::lower(Str::random(12)),
                'label' => $label,
                'submission_kind' => $outcome->submissionKind(),
                'outcome' => $outcome,
                'requires_reason' => $requiresReason,
                'requires_targets' => $requiresTargets || $outcome === AuditDecisionOutcome::Refuse,
                'is_active' => true,
                'position' => (int) AuditDecisionType::query()->max('position') + 1,
            ]);
            $this->journal->record('decision_type_created', $type, $supervisor, [
                'code' => $type->code, 'label' => $label, 'outcome' => $outcome->value, 'requires_reason' => $type->requires_reason, 'requires_targets' => $type->requires_targets,
            ]);

            return $type;
        });
    }

    /**
     * @throws WorkflowException
     */
    public function update(User $supervisor, AuditDecisionType $type, string $label, bool $requiresReason, bool $requiresTargets): void
    {
        $this->ensureSupervisor($supervisor);
        $label = $this->label($label, required: ! $this->isBuiltIn($type));

        DB::transaction(function () use ($supervisor, $type, $label, $requiresReason, $requiresTargets): void {
            $type = AuditDecisionType::query()->lockForUpdate()->findOrFail($type->id);
            $old = ['label' => $type->label, 'requires_reason' => $type->requires_reason, 'requires_targets' => $type->requires_targets];
            $type->forceFill([
                'label' => $label,
                'requires_reason' => $requiresReason,
                'requires_targets' => $requiresTargets || $type->outcome === AuditDecisionOutcome::Refuse,
            ]);

            if (! $type->isDirty()) {
                return;
            }

            $type->save();
            $this->journal->record('decision_type_updated', $type, $supervisor, [
                'code' => $type->code, 'old' => $old, 'new' => ['label' => $type->label, 'requires_reason' => $type->requires_reason, 'requires_targets' => $type->requires_targets],
            ]);
        });
    }

    /**
     * @throws WorkflowException
     */
    public function setActive(User $supervisor, AuditDecisionType $type, bool $active): void
    {
        $this->ensureSupervisor($supervisor);

        DB::transaction(function () use ($supervisor, $type, $active): void {
            $type = AuditDecisionType::query()->lockForUpdate()->findOrFail($type->id);

            if ($type->is_active === $active) {
                return;
            }

            if (! $active && ! AuditDecisionType::query()->where('outcome', $type->outcome)->where('is_active', true)->whereKeyNot($type->id)->lockForUpdate()->exists()) {
                throw new WorkflowException('last_decision_of_outcome', 409, ['outcome' => $type->outcome->label()]);
            }

            $type->forceFill(['is_active' => $active])->save();
            $this->journal->record($active ? 'decision_type_enabled' : 'decision_type_disabled', $type, $supervisor, ['code' => $type->code]);
        });
    }

    public function isBuiltIn(AuditDecisionType $type): bool
    {
        return AuditDecisionOutcome::tryFrom($type->code) !== null;
    }

    /**
     * @throws WorkflowException
     */
    private function label(string $label, bool $required): ?string
    {
        $label = trim($label);

        if ($label === '' && ! $required) {
            return null;
        }

        if (mb_strlen($label) < self::MIN_LABEL) {
            throw new WorkflowException('label_required');
        }

        return mb_substr($label, 0, 120);
    }

    /**
     * @throws WorkflowException
     */
    private function ensureSupervisor(User $user): void
    {
        if (! WorkflowRoles::isSupervisor($user)) {
            throw new WorkflowException('forbidden', 403);
        }
    }
}
