<?php

namespace App\DesignOfficeWorkflow;

use App\Enums\AuditConformity;
use App\Enums\AuditDossierStatus;
use App\Enums\AuditInspectionState;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\AuditDossierVersion;
use App\Models\AuditReopening;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Formal reopening of a closed audit, by a supervisor only: a written justification and the boxes to take up again
 * are recorded. Those boxes go back to the field (inspection to redo, conformity to assess again); every other
 * validation, decision, reserve and published dossier version stays as it is. The next closure authorises a new
 * complete version of the dossier.
 */
class ReopenAudit
{
    /** Shortest justification of a reopening. */
    public const int MIN_REASON = 10;

    public function __construct(private WorkflowGuard $guard, private WorkflowJournal $journal) {}

    /**
     * @param  list<int>  $boxIds  Boxes to take up again.
     *
     * @throws WorkflowException
     */
    public function handle(User $actor, Audit $audit, string $reason, array $boxIds): AuditReopening
    {
        if (! WorkflowRoles::isSupervisor($actor)) {
            throw new WorkflowException('supervisor_only', 403);
        }

        $reason = trim($reason);
        $boxIds = array_values(array_unique(array_map('intval', $boxIds)));

        return DB::transaction(function () use ($actor, $audit, $reason, $boxIds): AuditReopening {
            $audit = Audit::query()->lockForUpdate()->findOrFail($audit->id);
            $this->guard->ensureDesignOffice($audit);

            if (! $audit->isClosed()) {
                throw new WorkflowException('audit_not_closed', 409);
            }

            if (mb_strlen($reason) < self::MIN_REASON) {
                throw new WorkflowException('reopening_reason_required', 422, ['min' => self::MIN_REASON]);
            }

            if ($boxIds === []) {
                throw new WorkflowException('reopening_boxes_required');
            }

            $boxes = AuditBox::query()->where('audit_id', $audit->id)->whereNull('removed_at')->whereKey($boxIds)->orderBy('name')->lockForUpdate()->get();

            if ($boxes->count() !== count($boxIds)) {
                throw new WorkflowException('unknown_box', 404);
            }

            if (AuditDossierVersion::query()->where('audit_id', $audit->id)->whereIn('status', [AuditDossierStatus::Pending, AuditDossierStatus::Generating])->exists()) {
                throw new WorkflowException('dossier_in_progress', 409);
            }

            $reopening = AuditReopening::query()->create([
                'audit_id' => $audit->id, 'reopened_by' => $actor->id, 'reason' => mb_substr($reason, 0, 2000),
                'box_ids' => $boxes->modelKeys(), 'reopened_at' => now(),
            ]);
            $closedAt = $audit->closed_at;
            $audit->forceFill(['closed_at' => null, 'closed_by' => null])->save();

            foreach ($boxes as $box) {
                $before = ['inspection' => $box->inspection_state->value, 'conformity' => $box->conformity->value];
                $box->forceFill([
                    'inspection_state' => $box->responsible_id === null ? AuditInspectionState::ToDo : AuditInspectionState::InProgress,
                    'conformity' => AuditConformity::NotAssessed,
                ])->save();
                $this->journal->record('box_reopened', $box, $actor, ['box' => $box->name, 'old' => $before, 'new' => ['inspection' => $box->inspection_state->value, 'conformity' => $box->conformity->value], 'reopening_id' => $reopening->id]);
            }

            $this->journal->record('audit_reopened', $audit, $actor, [
                'cabinet' => $audit->cabinet_key, 'reason' => $reopening->reason, 'boxes' => $boxes->pluck('name')->all(),
                'closed_at' => $closedAt?->toIso8601String(), 'reopening_id' => $reopening->id,
            ]);

            return $reopening;
        });
    }
}
