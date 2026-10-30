<?php

namespace App\DesignOfficeWorkflow;

use App\Enums\AuditInterventionStatus;
use App\Enums\AuditOccurrencePhotoRole;
use App\Enums\AuditOccurrenceState;
use App\Enums\AuditRepairState;
use App\Enums\AuditRequestState;
use App\Enums\AuditSubmissionKind;
use App\Models\AuditBox;
use App\Models\AuditBoxRecheck;
use App\Models\AuditIntervention;
use App\Models\AuditOccurrence;
use App\Models\AuditSubmission;
use App\Models\AuditValidationRequest;
use App\Models\User;
use App\Settings\DesignOfficeWorkflowSettings;
use Illuminate\Support\Facades\DB;

/**
 * The responsible technician of a planned repair intervention sends the repairs of the box to the design office
 * (phase 2): one request per box with every repair recorded since (corrected occurrences), with the same lock and the
 * same deadline as an inspection. Refused while no intervention is planned on the box, while nothing is corrected, and
 * while an earlier box of theirs waits or must be done again. The saved recheck of the box (with its RECNOK workbook)
 * may be attached. What is sent is frozen in an immutable submission; the operation id of the phone makes a resent
 * submission answer the first one.
 */
class SubmitRepairs
{
    public function __construct(
        private WorkflowGuard $guard,
        private BoxTeam $team,
        private OccurrenceRules $occurrences,
        private ReviewQueue $queue,
        private WorkflowJournal $journal,
        private DesignOfficeWorkflowSettings $settings,
    ) {}

    /**
     * @return array{submission: AuditSubmission, request: AuditValidationRequest, duplicate: bool}
     *
     * @throws WorkflowException
     */
    public function handle(User $technician, AuditBox $box, string $operationId, bool $attachRecheck = false): array
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $operationId) !== 1) {
            throw new WorkflowException('operation_id_required');
        }

        return DB::transaction(function () use ($technician, $box, $operationId, $attachRecheck): array {
            $box = AuditBox::query()->with('audit')->lockForUpdate()->findOrFail($box->id);
            $this->guard->ensureOpenOrClientRepair($box->audit, $box);

            $repeat = AuditSubmission::query()->where('submitted_by', $technician->id)->where('client_operation_id', $operationId)->with('validationRequest')->first();

            if ($repeat !== null && $repeat->validationRequest !== null) {
                if ($repeat->audit_box_id !== $box->id || $repeat->kind !== AuditSubmissionKind::Repair) {
                    throw new WorkflowException('operation_id_reused', 409);
                }

                return ['submission' => $repeat, 'request' => $repeat->validationRequest, 'duplicate' => true];
            }

            $this->guard->ensureBoxInService($box);

            if ($box->responsible_id !== $technician->id) {
                throw new WorkflowException('not_responsible', 403);
            }

            $intervention = AuditIntervention::query()->where('audit_box_id', $box->id)->where('status', AuditInterventionStatus::Planned)->lockForUpdate()->first();

            if ($intervention === null || ! in_array($box->repair_state, [AuditRepairState::Planned, AuditRepairState::Refused], true)) {
                throw new WorkflowException('no_intervention', 409);
            }

            $this->team->ensureNotBlocked($technician, $box);

            $items = $this->occurrences->confirmedOn($box)->where('state', AuditOccurrenceState::Corrected)
                ->with(['type', 'photoLinks'])->orderBy('id')->lockForUpdate()->get();

            if ($items->isEmpty()) {
                throw new WorkflowException('nothing_to_submit');
            }

            $recheck = $attachRecheck ? $this->recheck($box) : null;
            $now = now();
            $submission = AuditSubmission::query()->create([
                'audit_box_id' => $box->id,
                'kind' => AuditSubmissionKind::Repair,
                'submitted_by' => $technician->id,
                'client_operation_id' => $operationId,
                'received_at' => $now,
                'snapshot' => [
                    'box' => ['id' => $box->id, 'name' => $box->name, 'kind' => $box->kind->value, 'support' => $box->support?->value, 'cabinet' => $box->audit->cabinet_key],
                    'intervention_id' => $intervention->id,
                    'items' => $items->map(fn (AuditOccurrence $occurrence) => [
                        'id' => $occurrence->id,
                        'reference' => $occurrence->reference,
                        'nature' => $occurrence->nature->value,
                        'type' => $occurrence->type->displayLabel(),
                        'description' => $occurrence->description,
                        'action' => $occurrence->action_taken,
                        'checks' => $occurrence->checks_done,
                        'observations' => $occurrence->observations,
                        'before' => $this->photoIds($occurrence, AuditOccurrencePhotoRole::Before),
                        'after' => $this->photoIds($occurrence, AuditOccurrencePhotoRole::After),
                    ])->values()->all(),
                    'recheck' => $recheck === null ? null : [
                        'id' => $recheck->id,
                        'is_conform' => $recheck->is_conform,
                        'saved_at' => $recheck->saved_at?->toIso8601String(),
                        'saved_by' => $recheck->saved_by_name,
                        'recnok_name' => $recheck->recnok_name,
                        'recnok_file_id' => $recheck->recnok_file_id,
                    ],
                ],
            ]);

            $request = AuditValidationRequest::query()->create([
                'audit_submission_id' => $submission->id,
                'audit_box_id' => $box->id,
                'state' => AuditRequestState::Queued,
                'queued_at' => $now,
                'deadline_at' => $now->copy()->addSeconds($this->settings->review_deadline_seconds),
            ]);

            $box->forceFill(['repair_state' => AuditRepairState::Submitted])->save();
            $intervention->forceFill(['status' => AuditInterventionStatus::Submitted, 'submitted_at' => $now])->save();
            $this->journal->record('repairs_submitted', $submission, $technician, [
                'box' => $box->name, 'request_id' => $request->id, 'intervention_id' => $intervention->id,
                'repairs' => $items->pluck('reference')->all(), 'recheck' => $recheck?->id, 'correlation_id' => $operationId,
            ]);

            $this->queue->dispatch();

            return ['submission' => $submission, 'request' => $request->refresh(), 'duplicate' => false];
        });
    }

    /**
     * @throws WorkflowException
     */
    private function recheck(AuditBox $box): AuditBoxRecheck
    {
        $recheck = AuditBoxRecheck::query()->where('audit_box_id', $box->id)->whereNotNull('saved_at')->first();

        if ($recheck === null) {
            throw new WorkflowException('no_recheck', 422, ['box' => $box->name]);
        }

        return $recheck;
    }

    /**
     * @return list<int>
     */
    private function photoIds(AuditOccurrence $occurrence, AuditOccurrencePhotoRole $role): array
    {
        return $occurrence->photoLinks->where('role', $role)->pluck('audit_photo_id')->sort()->values()->all();
    }
}
