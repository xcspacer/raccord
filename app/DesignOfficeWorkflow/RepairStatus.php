<?php

namespace App\DesignOfficeWorkflow;

use App\Enums\AuditInterventionStatus;
use App\Enums\AuditOccurrenceState;
use App\Enums\AuditSubmissionKind;
use App\Models\AuditBox;
use App\Models\AuditDecision;
use App\Models\AuditIntervention;
use App\Models\AuditOccurrence;
use App\Models\AuditRepairItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Phase 2 of a box as the phone polls it (part of BoxStatus): the open repair intervention and whether the person
 * belongs to it, the repairs still to do or to submit, and the verdict of each repair of the latest repairs decision.
 */
class RepairStatus
{
    public function __construct(private RepairPlanning $planning) {}

    /**
     * @return array{phase: int, intervention: array<string, mixed>|null, items: list<array<string, mixed>>, can_submit: bool, last_decision: array<string, mixed>|null}
     */
    public function for(AuditBox $box, User $viewer): array
    {
        $box->loadMissing('audit');
        $intervention = AuditIntervention::query()->where('audit_box_id', $box->id)
            ->whereIn('status', [AuditInterventionStatus::Planned, AuditInterventionStatus::Submitted])
            ->with(['responsible', 'members'])->first();
        $items = $this->planning->openRepairs($box);
        $decision = AuditDecision::query()
            ->whereHas('request', fn (Builder $requests) => $requests->where('audit_box_id', $box->id)
                ->whereHas('submission', fn (Builder $submissions) => $submissions->where('kind', AuditSubmissionKind::Repair)))
            ->with(['type', 'decider', 'repairItems.occurrence'])->latest('id')->first();

        return [
            'phase' => $box->audit->phase->value,
            'intervention' => $intervention === null ? null : [
                'id' => $intervention->id,
                'status' => $intervention->status->value,
                'planned_on' => $intervention->planned_on->toDateString(),
                'note' => $intervention->note,
                'responsible' => ['id' => $intervention->responsible->id, 'name' => $intervention->responsible->name],
                'members' => $intervention->members->map(fn (User $member) => ['id' => $member->id, 'name' => $member->name])->values()->all(),
                'is_member' => $intervention->members->contains('id', $viewer->id),
            ],
            'items' => $items->map(fn (AuditOccurrence $occurrence) => [
                'id' => $occurrence->id,
                'reference' => $occurrence->reference,
                'nature' => $occurrence->nature->value,
                'state' => $occurrence->state->value,
                'state_label' => $occurrence->state->label(),
                'type' => $occurrence->type->displayLabel(),
            ])->values()->all(),
            'can_submit' => $intervention !== null && $intervention->status === AuditInterventionStatus::Planned
                && $box->responsible_id === $viewer->id && $items->contains(fn (AuditOccurrence $occurrence) => $occurrence->state === AuditOccurrenceState::Corrected),
            'last_decision' => $decision === null ? null : [
                'label' => $decision->type->displayLabel(),
                'outcome' => $decision->type->outcome->value,
                'reason' => $decision->reason,
                'decided_at' => $decision->decided_at->toIso8601String(),
                'by' => $decision->decider->name,
                'items' => $decision->repairItems->map(fn (AuditRepairItem $item) => [
                    'id' => $item->audit_occurrence_id,
                    'reference' => $item->occurrence->reference,
                    'is_accepted' => $item->is_accepted,
                    'reason' => $item->reason,
                ])->values()->all(),
            ],
        ];
    }
}
