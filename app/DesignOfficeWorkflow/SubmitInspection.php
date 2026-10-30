<?php

namespace App\DesignOfficeWorkflow;

use App\Enums\AuditFolder;
use App\Enums\AuditInspectionState;
use App\Enums\AuditRequestState;
use App\Enums\AuditSubmissionKind;
use App\Models\AuditBox;
use App\Models\AuditBoxMessage;
use App\Models\AuditMissingProof;
use App\Models\AuditPhoto;
use App\Models\AuditSubmission;
use App\Models\AuditValidationRequest;
use App\Models\User;
use App\Settings\DesignOfficeWorkflowSettings;
use Illuminate\Support\Facades\DB;

/**
 * The responsible technician sends the inspection of a box to the design office (phase 1). Refused while an earlier
 * box of theirs waits or must be done again, while a blocking observation of the design office is unresolved, while an
 * optical anomaly is neither corrected nor justified in writing, and while a mandatory folder has no photo and no
 * written justification. What is sent is frozen in an immutable submission; the review clock starts only now, when
 * the server has received and checked it (a submission queued offline does not run the clock). The operation id of
 * the phone makes a resent submission answer the first one instead of creating another.
 */
class SubmitInspection
{
    /** Shortest written justification of a missing mandatory folder. */
    public const int MIN_JUSTIFICATION = 5;

    public function __construct(
        private WorkflowGuard $guard,
        private BoxTeam $team,
        private RequiredFolders $folders,
        private OccurrenceRules $occurrences,
        private ReviewQueue $queue,
        private WorkflowJournal $journal,
        private DesignOfficeWorkflowSettings $settings,
    ) {}

    /**
     * @param  array<string, string>  $justifications  Written justification per missing folder (folder value => text).
     * @return array{submission: AuditSubmission, request: AuditValidationRequest, duplicate: bool}
     *
     * @throws WorkflowException
     */
    public function handle(User $technician, AuditBox $box, string $operationId, array $justifications = []): array
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $operationId) !== 1) {
            throw new WorkflowException('operation_id_required');
        }

        return DB::transaction(function () use ($technician, $box, $operationId, $justifications): array {
            $box = AuditBox::query()->with('audit')->lockForUpdate()->findOrFail($box->id);
            $this->guard->ensureOpen($box->audit);

            $repeat = AuditSubmission::query()->where('submitted_by', $technician->id)->where('client_operation_id', $operationId)->with('validationRequest')->first();

            if ($repeat !== null && $repeat->validationRequest !== null) {
                if ($repeat->audit_box_id !== $box->id || $repeat->kind !== AuditSubmissionKind::Inspection) {
                    throw new WorkflowException('operation_id_reused', 409);
                }

                return ['submission' => $repeat, 'request' => $repeat->validationRequest, 'duplicate' => true];
            }

            $this->guard->ensureBoxInService($box);

            if ($box->responsible_id !== $technician->id) {
                throw new WorkflowException('not_responsible', 403);
            }

            if (! in_array($box->inspection_state, AuditInspectionState::submittable(), true)) {
                throw new WorkflowException($box->inspection_state === AuditInspectionState::Submitted ? 'already_submitted' : 'not_submittable', 409, ['state' => $box->inspection_state->value]);
            }

            $this->team->ensureNotBlocked($technician, $box);

            $blocking = AuditBoxMessage::query()->where('audit_box_id', $box->id)->where('is_blocking', true)->whereNull('resolved_at')->count();

            if ($blocking > 0) {
                throw new WorkflowException('blocking_messages', 409, ['count' => $blocking]);
            }

            $optical = $this->occurrences->unaddressedOptical($box);

            if ($optical !== []) {
                throw new WorkflowException('optical_not_addressed', 422, ['references' => $optical]);
            }

            $photos = AuditPhoto::query()->where('audit_box_id', $box->id)->with('file')->orderBy('id')->get();
            $missing = $this->folders->missing($box, $photos);
            $justified = $this->justified($missing, $justifications);
            $unjustified = array_values(array_filter($missing, fn (AuditFolder $folder) => ! isset($justified[$folder->value])));

            if ($unjustified !== []) {
                throw new WorkflowException('missing_proofs', 422, ['folders' => array_map(fn (AuditFolder $folder) => $folder->value, $unjustified)]);
            }

            $now = now();
            $submission = AuditSubmission::query()->create([
                'audit_box_id' => $box->id,
                'kind' => AuditSubmissionKind::Inspection,
                'submitted_by' => $technician->id,
                'client_operation_id' => $operationId,
                'received_at' => $now,
                'snapshot' => [
                    'box' => ['id' => $box->id, 'name' => $box->name, 'kind' => $box->kind->value, 'support' => $box->support?->value, 'cabinet' => $box->audit->cabinet_key, 'mode' => $box->audit->effectiveMode()->value],
                    'photos' => $photos->map(fn (AuditPhoto $photo) => [
                        'id' => $photo->id, 'folder' => $photo->folder, 'subfolder' => $photo->subfolder, 'sha256' => $photo->file?->getAttribute('sha256'),
                        'captured_at' => $photo->captured_at->toIso8601String(), 'is_geo_missing' => $photo->is_geo_missing, 'is_imported' => $photo->is_imported,
                    ])->values()->all(),
                    'missing' => collect($justified)->map(fn (string $text, string $folder) => ['folder' => $folder, 'justification' => $text])->values()->all(),
                    'occurrences' => $this->occurrences->snapshot($box),
                ],
            ]);

            foreach ($justified as $folder => $text) {
                AuditMissingProof::query()->create([
                    'audit_box_id' => $box->id, 'audit_submission_id' => $submission->id, 'folder' => $folder,
                    'justification' => $text, 'justified_by' => $technician->id, 'created_at' => $now,
                ]);
            }

            $request = AuditValidationRequest::query()->create([
                'audit_submission_id' => $submission->id,
                'audit_box_id' => $box->id,
                'state' => AuditRequestState::Queued,
                'queued_at' => $now,
                'deadline_at' => $now->copy()->addSeconds($this->settings->review_deadline_seconds),
            ]);

            $box->forceFill(['inspection_state' => AuditInspectionState::Submitted])->save();
            $this->journal->record('submission_received', $submission, $technician, [
                'box' => $box->name, 'request_id' => $request->id, 'photos' => $photos->count(), 'missing' => count($justified), 'correlation_id' => $operationId,
            ]);

            $this->queue->dispatch();

            return ['submission' => $submission, 'request' => $request->refresh(), 'duplicate' => false];
        });
    }

    /**
     * Justifications long enough for folders that are really missing (anything else sent is ignored).
     *
     * @param  list<AuditFolder>  $missing
     * @param  array<string, string>  $justifications
     * @return array<string, string>
     */
    private function justified(array $missing, array $justifications): array
    {
        $justified = [];

        foreach ($missing as $folder) {
            $text = trim((string) ($justifications[$folder->value] ?? ''));

            if (mb_strlen($text) >= self::MIN_JUSTIFICATION) {
                $justified[$folder->value] = mb_substr($text, 0, 2000);
            }
        }

        return $justified;
    }
}
