<?php

namespace App\DesignOfficeWorkflow;

use App\Enums\AuditAnomalyNature;
use App\Enums\AuditConformity;
use App\Models\AuditBox;
use App\Models\AuditOccurrence;
use App\Models\AuditOccurrenceBox;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * An optical anomaly may hit several boxes of the same audit (a common cable): a technician of the occurrence's team
 * proposes an impacted box with a technical justification, which imposes nothing on that box until the design office
 * (or a supervisor) confirms it — or excludes it with a reason. When they add a box themselves it is confirmed at
 * once. The origin box can never be excluded. Each confirmed box then gets its own result (conforming or not).
 */
class ImpactedBoxes
{
    public const int MIN_JUSTIFICATION = 5;

    public const int MIN_EXCLUSION_REASON = 3;

    public function __construct(private WorkflowGuard $guard, private WorkflowJournal $journal, private LegacyBoxStatus $legacyStatus, private Conformity $conformity) {}

    /**
     * @return array{confirmed: bool}
     *
     * @throws WorkflowException
     */
    public function propose(User $actor, AuditOccurrence $occurrence, AuditBox $box, string $justification): array
    {
        return DB::transaction(function () use ($actor, $occurrence, $box, $justification): array {
            $occurrence = $this->locked($occurrence);
            $this->ensureEditable($occurrence);

            if ($occurrence->nature !== AuditAnomalyNature::Optical) {
                throw new WorkflowException('not_optical', 409);
            }

            $reviewer = WorkflowRoles::isSupervisor($actor) || WorkflowRoles::isReviewer($actor);

            if (! $reviewer && (! WorkflowRoles::isTechnician($actor) || ! OccurrenceLifecycle::isTeamMember($actor, OccurrenceLifecycle::confirmedBoxes($occurrence)))) {
                throw new WorkflowException('not_team_member', 403);
            }

            $box = AuditBox::query()->lockForUpdate()->findOrFail($box->id);

            if ($box->audit_id !== $occurrence->audit_id) {
                throw new WorkflowException('other_audit');
            }

            $this->guard->ensureBoxInService($box);
            $justification = trim($justification);

            if (mb_strlen($justification) < self::MIN_JUSTIFICATION) {
                throw new WorkflowException('impact_justification_required');
            }

            $link = AuditOccurrenceBox::query()->where('audit_occurrence_id', $occurrence->id)->where('audit_box_id', $box->id)->lockForUpdate()->first();

            if ($link?->is_origin) {
                throw new WorkflowException('origin_box', 409);
            }

            if ($link !== null && ! $reviewer && ($link->confirmed_at !== null || $link->excluded_at !== null)) {
                throw new WorkflowException('impact_already_decided', 409, ['box' => $box->name]);
            }

            $now = now();
            $link ??= new AuditOccurrenceBox(['audit_occurrence_id' => $occurrence->id, 'audit_box_id' => $box->id, 'added_by' => $actor->id, 'added_at' => $now, 'conformity' => AuditConformity::NonConforming]);
            $link->forceFill([
                'justification' => mb_substr($justification, 0, 2000),
                'is_suggested' => ! $reviewer,
                'confirmed_by' => $reviewer ? $actor->id : null,
                'confirmed_at' => $reviewer ? $now : null,
                'excluded_by' => null, 'excluded_at' => null, 'exclusion_reason' => null,
            ])->save();

            if ($reviewer) {
                $this->legacyStatus->occurrenceConfirmed($actor, $box, $occurrence);
                $this->conformity->recompute($actor, $box);
            }

            $this->journal->record($reviewer ? 'occurrence_box_added' : 'occurrence_box_proposed', $occurrence, $actor, [
                'reference' => $occurrence->reference, 'box' => $box->name, 'box_id' => $box->id, 'reason' => $link->justification,
            ]);

            return ['confirmed' => $reviewer];
        });
    }

    /**
     * The design office (or a supervisor) confirms a proposed box, or excludes it with a reason.
     *
     * @throws WorkflowException
     */
    public function decide(User $actor, AuditOccurrence $occurrence, AuditBox $box, bool $confirm, ?string $reason = null): void
    {
        DB::transaction(function () use ($actor, $occurrence, $box, $confirm, $reason): void {
            $occurrence = $this->locked($occurrence);
            $this->ensureEditable($occurrence);

            if (! WorkflowRoles::isSupervisor($actor) && ! WorkflowRoles::isReviewer($actor)) {
                throw new WorkflowException('forbidden', 403);
            }

            $link = AuditOccurrenceBox::query()->where('audit_occurrence_id', $occurrence->id)->where('audit_box_id', $box->id)->with('box')->lockForUpdate()->first()
                ?? throw new WorkflowException('impact_not_found', 404);

            if ($link->is_origin) {
                throw new WorkflowException('origin_box', 409);
            }

            $reason = trim((string) $reason);

            if (! $confirm && mb_strlen($reason) < self::MIN_EXCLUSION_REASON) {
                throw new WorkflowException('exclusion_reason_required');
            }

            $now = now();
            $link->forceFill($confirm
                ? ['is_suggested' => false, 'confirmed_by' => $actor->id, 'confirmed_at' => $now, 'excluded_by' => null, 'excluded_at' => null, 'exclusion_reason' => null]
                : ['excluded_by' => $actor->id, 'excluded_at' => $now, 'exclusion_reason' => mb_substr($reason, 0, 2000)])->save();

            if ($confirm) {
                $this->legacyStatus->occurrenceConfirmed($actor, $link->box, $occurrence);
                $this->conformity->recompute($actor, $link->box);
            }

            $this->journal->record($confirm ? 'occurrence_box_confirmed' : 'occurrence_box_excluded', $occurrence, $actor, [
                'reference' => $occurrence->reference, 'box' => $link->box->name, 'box_id' => $link->audit_box_id, 'reason' => $reason === '' ? null : $reason,
            ]);
        });
    }

    /**
     * Result of the occurrence on one confirmed box: conforming or not, with an optional note. Technicians may give it
     * for a box they work on; the design office and supervisors for any.
     *
     * @throws WorkflowException
     */
    public function setResult(User $actor, AuditOccurrence $occurrence, AuditBox $box, AuditConformity $conformity, ?string $note = null): void
    {
        if (! in_array($conformity, [AuditConformity::Conforming, AuditConformity::NonConforming], true)) {
            throw new WorkflowException('bad_result');
        }

        DB::transaction(function () use ($actor, $occurrence, $box, $conformity, $note): void {
            $occurrence = $this->locked($occurrence);
            $this->guard->ensureOpen($occurrence->audit);

            $link = AuditOccurrenceBox::query()->where('audit_occurrence_id', $occurrence->id)->where('audit_box_id', $box->id)
                ->whereNotNull('confirmed_at')->whereNull('excluded_at')->with('box')->lockForUpdate()->first()
                ?? throw new WorkflowException('impact_not_found', 404);

            $reviewer = WorkflowRoles::isSupervisor($actor) || WorkflowRoles::isReviewer($actor);

            if (! $reviewer && (! WorkflowRoles::isTechnician($actor) || ! $link->box->hasTeamMember($actor))) {
                throw new WorkflowException('not_team_member', 403);
            }

            $before = $link->conformity;
            $link->forceFill(['conformity' => $conformity, 'note' => mb_substr(trim((string) $note), 0, 1000) ?: null])->save();
            $this->journal->record('occurrence_box_result', $occurrence, $actor, [
                'reference' => $occurrence->reference, 'box' => $link->box->name, 'box_id' => $link->audit_box_id, 'old' => $before->value, 'new' => $conformity->value, 'note' => $link->note,
            ]);
        });
    }

    /**
     * @throws WorkflowException
     */
    private function ensureEditable(AuditOccurrence $occurrence): void
    {
        $this->guard->ensureOpen($occurrence->audit);

        if ($occurrence->state->isClosed()) {
            throw new WorkflowException('occurrence_closed', 409, ['reference' => $occurrence->reference]);
        }
    }

    private function locked(AuditOccurrence $occurrence): AuditOccurrence
    {
        return AuditOccurrence::query()->with('audit')->lockForUpdate()->findOrFail($occurrence->id);
    }
}
