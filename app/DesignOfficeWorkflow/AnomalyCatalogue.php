<?php

namespace App\DesignOfficeWorkflow;

use App\Enums\AuditAnomalyNature;
use App\Models\AuditAnomalyType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The anomaly types the supervisors keep. A type gets a label (at least 3 characters) and a nature (optical or
 * mechanical) that never changes once created; it is never deleted — switched off instead, so occurrences keep
 * pointing to it and it is no longer offered. A built-in type keeps its translated label unless a supervisor types
 * one (clearing it brings the translation back).
 */
class AnomalyCatalogue
{
    public const int MIN_LABEL = 3;

    public function __construct(private WorkflowJournal $journal) {}

    /**
     * @throws WorkflowException
     */
    public function create(User $supervisor, string $label, AuditAnomalyNature $nature): AuditAnomalyType
    {
        $this->ensureSupervisor($supervisor);
        $label = $this->label($label, required: true);

        return DB::transaction(function () use ($supervisor, $label, $nature): AuditAnomalyType {
            $type = AuditAnomalyType::query()->create([
                'label' => $label, 'nature' => $nature, 'is_active' => true,
                'position' => (int) AuditAnomalyType::query()->max('position') + 1,
            ]);
            $this->journal->record('anomaly_type_created', $type, $supervisor, ['label' => $label, 'nature' => $nature->value]);

            return $type;
        });
    }

    /**
     * @throws WorkflowException
     */
    public function rename(User $supervisor, AuditAnomalyType $type, string $label): void
    {
        $this->ensureSupervisor($supervisor);
        $label = $this->label($label, required: $type->code === null);

        DB::transaction(function () use ($supervisor, $type, $label): void {
            $type = AuditAnomalyType::query()->lockForUpdate()->findOrFail($type->id);
            $before = $type->label;

            if ($before === $label) {
                return;
            }

            $type->forceFill(['label' => $label])->save();
            $this->journal->record('anomaly_type_renamed', $type, $supervisor, ['old' => $before, 'new' => $label]);
        });
    }

    /**
     * @throws WorkflowException
     */
    public function setActive(User $supervisor, AuditAnomalyType $type, bool $active): void
    {
        $this->ensureSupervisor($supervisor);

        DB::transaction(function () use ($supervisor, $type, $active): void {
            $type = AuditAnomalyType::query()->lockForUpdate()->findOrFail($type->id);

            if ($type->is_active === $active) {
                return;
            }

            $type->forceFill(['is_active' => $active])->save();
            $this->journal->record($active ? 'anomaly_type_enabled' : 'anomaly_type_disabled', $type, $supervisor, ['label' => $type->displayLabel()]);
        });
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

        return mb_substr($label, 0, 160);
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
