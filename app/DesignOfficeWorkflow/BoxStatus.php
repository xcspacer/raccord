<?php

namespace App\DesignOfficeWorkflow;

use App\Enums\AuditFolder;
use App\Enums\AuditPositionSource;
use App\Models\AuditBox;
use App\Models\AuditBoxMessage;
use App\Models\AuditBoxPosition;
use App\Models\AuditDecision;
use App\Models\AuditPhoto;
use App\Models\AuditPhotoAnnotation;
use App\Models\AuditSynopticCorrection;
use App\Models\AuditValidationRequest;
use App\Models\User;

/**
 * Where a box stands in the design office workflow, as the phone polls it: its states and team, whether the person is
 * held by another box, the request waiting for the design office and its decision once taken (how the technician
 * learns it), the mandatory folders still without photo, the internal messages (unresolved blocking ones counted) and
 * the photos the design office drew on. It also carries what the box screen shows of the rest of the workflow: the
 * occurrences of the box (with the anomaly types to choose from), the open pause of the person (on any box), their
 * latest position proposal and their synoptic corrections about the box (every one for the design office and the
 * supervisors), and whether the box takes repair work although the audit is closed (an occurrence declared by the
 * client is being repaired, spec 13-44).
 */
class BoxStatus
{
    public function __construct(private BoxTeam $team, private RequiredFolders $folders, private BoxMessages $messages, private RepairStatus $repairs, private OccurrenceView $occurrences, private Pauses $pauses) {}

    /**
     * @return array<string, mixed>
     */
    public function for(AuditBox $box, User $viewer): array
    {
        $box->loadMissing(['audit', 'responsible', 'participants']);
        $request = AuditValidationRequest::query()->where('audit_box_id', $box->id)->latest('id')->with(['decision.type', 'decision.decider'])->first();
        $blocking = $this->team->blockingBoxOf($viewer, $box);
        $photos = AuditPhoto::query()->where('audit_box_id', $box->id)->get(['id', 'folder', 'subfolder']);

        return [
            'workflow' => $box->audit->workflow->value,
            'box' => [
                'id' => $box->id,
                'name' => $box->name,
                'inspection_state' => $box->inspection_state->value,
                'inspection_state_label' => $box->inspection_state->label(),
                'repair_state' => $box->repair_state->value,
                'conformity' => $box->conformity->value,
                'responsible' => $box->responsible === null ? null : ['id' => $box->responsible->id, 'name' => $box->responsible->name],
                'is_responsible' => $box->responsible_id === $viewer->id,
                'participants' => $box->participants->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])->values()->all(),
                'handover_requested' => $box->handover_requested_at !== null,
                'is_continuation_authorized' => $box->is_continuation_authorized,
            ],
            'blocked_by' => $blocking === null ? null : ['id' => $blocking->id, 'name' => $blocking->name, 'state' => $blocking->inspection_state->value],
            'missing_folders' => array_map(fn (AuditFolder $folder) => ['folder' => $folder->value, 'label' => $folder->label()], $this->folders->missing($box, $photos)),
            'blocking_messages' => AuditBoxMessage::query()->where('audit_box_id', $box->id)->where('is_blocking', true)->whereNull('resolved_at')->count(),
            'messages' => $this->messages->thread($box, $viewer),
            'annotated_photos' => AuditPhotoAnnotation::query()->whereIn('audit_photo_id', $photos->pluck('id'))->distinct()->pluck('audit_photo_id')->map(fn (mixed $id) => (int) $id)->values()->all(),
            'request' => $request === null ? null : [
                'id' => $request->id,
                'kind' => $request->submission()->value('kind'),
                'state' => $request->state->value,
                'state_label' => $request->state->label(),
                'queued_at' => $request->queued_at->toIso8601String(),
                'deadline_at' => $request->deadline_at->toIso8601String(),
                'is_late' => $request->isLate(),
            ],
            'decision' => $request?->decision === null ? null : $this->decision($request->decision),
            'repairs' => $this->repairs->for($box, $viewer),
            'client_repair' => $box->audit->isClosed() && WorkflowGuard::hasClientRepair($box),
            'occurrences' => $this->occurrences->forBox($box),
            'current_pause' => $this->pause($viewer, $box),
            'position_proposal' => $this->positionProposal($box, $viewer),
            'synoptic_corrections' => $this->synopticCorrections($box, $viewer),
        ];
    }

    /**
     * The pause the person has open, on this box or another one (one at a time).
     *
     * @return array{id: int, box_id: int|null, box: string|null, is_here: bool, started_at: string}|null
     */
    private function pause(User $viewer, AuditBox $box): ?array
    {
        $pause = $this->pauses->openPauseOf($viewer)?->load('box:id,name');

        return $pause === null ? null : [
            'id' => $pause->id,
            'box_id' => $pause->audit_box_id,
            'box' => $pause->box?->name,
            'is_here' => $pause->audit_box_id === $box->id,
            'started_at' => $pause->started_at->toIso8601String(),
        ];
    }

    /**
     * @return array{state: string, rejection_reason: string|null, at: string}|null
     */
    private function positionProposal(AuditBox $box, User $viewer): ?array
    {
        $position = AuditBoxPosition::query()->where('audit_box_id', $box->id)->where('source', AuditPositionSource::Proposed)
            ->where('recorded_by', $viewer->id)->latest('id')->first();

        return $position === null ? null : [
            'state' => match (true) {
                $position->approved_at !== null => 'approved',
                $position->rejected_at !== null => 'rejected',
                default => 'pending',
            },
            'rejection_reason' => $position->rejection_reason,
            'at' => $position->created_at->toIso8601String(),
        ];
    }

    /**
     * @return list<array{id: int, text: string, state: string, state_label: string, decision_note: string|null, at: string}>
     */
    private function synopticCorrections(AuditBox $box, User $viewer): array
    {
        $office = WorkflowRoles::isReviewer($viewer) || WorkflowRoles::isSupervisor($viewer);

        return AuditSynopticCorrection::query()->where('audit_box_id', $box->id)
            ->when(! $office, fn ($query) => $query->where('proposed_by', $viewer->id))
            ->latest('id')->limit(20)->get()
            ->map(fn (AuditSynopticCorrection $correction) => [
                'id' => $correction->id,
                'text' => $correction->body,
                'state' => $correction->state->value,
                'state_label' => $correction->state->label(),
                'decision_note' => $correction->decision_note,
                'at' => $correction->created_at->toIso8601String(),
            ])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function decision(AuditDecision $decision): array
    {
        return [
            'code' => $decision->type->code,
            'label' => $decision->type->displayLabel(),
            'outcome' => $decision->type->outcome->value,
            'reason' => $decision->reason,
            'targets' => $decision->targets ?? [],
            'decided_at' => $decision->decided_at->toIso8601String(),
            'by' => $decision->decider->name,
        ];
    }
}
