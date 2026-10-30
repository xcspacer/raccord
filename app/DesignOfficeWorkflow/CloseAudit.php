<?php

namespace App\DesignOfficeWorkflow;

use App\Enums\AuditDossierKind;
use App\Enums\AuditDossierStatus;
use App\Enums\AuditWorkflowPhase;
use App\Models\Audit;
use App\Models\AuditDossierVersion;
use App\Models\AuditReopening;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A supervisor closes an audit of the design office workflow, after an express confirmation, once every closing check
 * passes (ClosingChecks). The closure is irreversible (only a formal reopening, ReopenAudit, brings the audit back) and
 * is the supervisor's authorisation to publish the official dossier: the version to generate is recorded in the same
 * transaction (the first one, or a new complete version when the audit was reopened), waiting for its generation.
 */
class CloseAudit
{
    public function __construct(private WorkflowGuard $guard, private ClosingChecks $checks, private WorkflowJournal $journal) {}

    /**
     * @throws WorkflowException
     */
    public function handle(User $actor, Audit $audit, bool $confirmed): AuditDossierVersion
    {
        if (! WorkflowRoles::isSupervisor($actor)) {
            throw new WorkflowException('supervisor_only', 403);
        }

        if (! $confirmed) {
            throw new WorkflowException('confirmation_required');
        }

        return DB::transaction(function () use ($actor, $audit): AuditDossierVersion {
            $audit = Audit::query()->lockForUpdate()->findOrFail($audit->id);
            $this->guard->ensureDesignOffice($audit);

            if ($audit->isClosed()) {
                throw new WorkflowException('already_closed', 409, ['at' => $audit->closed_at?->toIso8601String()]);
            }

            if (AuditDossierVersion::query()->where('audit_id', $audit->id)->whereIn('status', [AuditDossierStatus::Pending, AuditDossierStatus::Generating])->exists()) {
                throw new WorkflowException('dossier_in_progress', 409);
            }

            $report = $this->checks->report($audit);

            if (! $report['ok']) {
                throw new WorkflowException('closing_blocked', 409, ['blockers' => array_column($report['blockers'], 'code')]);
            }

            $before = $audit->phase;
            $audit->forceFill(['closed_at' => now(), 'closed_by' => $actor->id, 'phase' => AuditWorkflowPhase::Closure])->save();

            $previous = (int) AuditDossierVersion::query()->where('audit_id', $audit->id)->max('version_number');
            $version = AuditDossierVersion::query()->create([
                'audit_id' => $audit->id,
                'version_number' => $previous + 1,
                'kind' => $previous === 0 ? AuditDossierKind::Initial : AuditDossierKind::Update,
                'status' => AuditDossierStatus::Pending,
                'reason' => $previous === 0 ? null : AuditReopening::query()->where('audit_id', $audit->id)->latest('id')->value('reason'),
                'requested_by' => $actor->id,
                'authorized_by' => $actor->id,
            ]);

            $this->journal->record('audit_closed', $audit, $actor, [
                'cabinet' => $audit->cabinet_key, 'old_phase' => $before->value, 'new_phase' => $audit->phase->value,
                'dossier_version' => $version->version_number, 'summary' => $report['summary'], 'warnings' => array_column($report['warnings'], 'code'),
            ]);

            return $version;
        });
    }
}
