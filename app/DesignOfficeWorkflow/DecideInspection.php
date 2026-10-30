<?php

namespace App\DesignOfficeWorkflow;

use App\Enums\AuditBoxMessageKind;
use App\Enums\AuditDecisionOutcome;
use App\Enums\AuditInspectionState;
use App\Enums\AuditRequestState;
use App\Enums\AuditSubmissionKind;
use App\Models\AuditBox;
use App\Models\AuditBoxMessage;
use App\Models\AuditDecision;
use App\Models\AuditDecisionType;
use App\Models\AuditMissingProof;
use App\Models\AuditValidationRequest;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The design office decides the inspection of a box (phase 1), once: the first decision recorded wins (the request
 * is locked and the decision is unique per request), any later attempt is told who decided what and when. The
 * decision comes from the catalogue; its reason is mandatory unless the type says otherwise, and a refusal says what
 * must be corrected. With an optical anomaly waiting, only continuation, a refusal or an additional control can be
 * decided — and continuation needs one. Every justification of a missing photo must be accepted or refused, and a
 * refused one forbids a plain validation. Times are the server's.
 */
class DecideInspection
{
    /** Shortest reason accepted when one is required. */
    public const int MIN_REASON = 3;

    public function __construct(
        private WorkflowGuard $guard,
        private OccurrenceRules $occurrences,
        private Conformity $conformity,
        private ReviewQueue $queue,
        private WorkflowJournal $journal,
    ) {}

    /**
     * @param  list<string>  $targets  What must be corrected (refusal).
     * @param  array<string, bool>  $proofs  Verdict per justified missing folder (folder value => accepted).
     *
     * @throws WorkflowException
     */
    public function handle(User $actor, AuditValidationRequest $request, string $decisionCode, ?string $reason = null, array $targets = [], array $proofs = []): AuditDecision
    {
        try {
            $decision = DB::transaction(fn (): AuditDecision => $this->decide($actor, $request, $decisionCode, trim((string) $reason), $targets, $proofs));
        } catch (UniqueConstraintViolationException) {
            throw $this->alreadyDecided($request);
        }

        $this->queue->dispatch();

        return $decision;
    }

    /**
     * @param  list<string>  $targets
     * @param  array<string, bool>  $proofs
     */
    private function decide(User $actor, AuditValidationRequest $request, string $decisionCode, string $reason, array $targets, array $proofs): AuditDecision
    {
        $request = AuditValidationRequest::query()->with('submission')->lockForUpdate()->findOrFail($request->id);

        if ($request->isDecided()) {
            throw $this->alreadyDecided($request);
        }

        if (! WorkflowRoles::isSupervisor($actor) && $request->assigned_to !== $actor->id) {
            throw new WorkflowException('not_assigned', 404);
        }

        $box = AuditBox::query()->with('audit')->lockForUpdate()->findOrFail($request->audit_box_id);
        $this->guard->ensureDesignOffice($box->audit);

        if ($request->submission->kind !== AuditSubmissionKind::Inspection) {
            throw new WorkflowException('wrong_kind', 409);
        }

        $type = AuditDecisionType::query()->where('code', $decisionCode)->where('is_active', true)->where('submission_kind', AuditSubmissionKind::Inspection)->first()
            ?? throw new WorkflowException('unknown_decision');
        $outcome = $type->outcome;
        $targets = array_values(array_filter(array_map(fn (mixed $target) => mb_substr(trim((string) $target), 0, 500), $targets), fn (string $target) => $target !== ''));

        if ($type->requires_reason && mb_strlen($reason) < self::MIN_REASON) {
            throw new WorkflowException('reason_required');
        }

        if (($type->requires_targets || $outcome === AuditDecisionOutcome::Refuse) && $targets === []) {
            throw new WorkflowException('targets_required');
        }

        $pending = $this->occurrences->pendingOptical($box);

        if ($pending->isNotEmpty() && in_array($outcome, [AuditDecisionOutcome::Validate, AuditDecisionOutcome::ValidateWithReserves], true)) {
            throw new WorkflowException('optical_pending', 422, ['references' => $pending->pluck('reference')->all()]);
        }

        if ($pending->isEmpty() && $outcome === AuditDecisionOutcome::AuthorizeContinuation) {
            throw new WorkflowException('nothing_pending');
        }

        $this->decideProofs($actor, $request, $outcome, $proofs);

        $decision = AuditDecision::query()->create([
            'audit_validation_request_id' => $request->id,
            'audit_decision_type_id' => $type->id,
            'decided_by' => $actor->id,
            'reason' => $reason === '' ? null : mb_substr($reason, 0, 2000),
            'targets' => $targets === [] ? null : $targets,
            'decided_at' => now(),
        ]);
        $request->forceFill(['state' => AuditRequestState::Decided, 'decided_at' => $decision->decided_at])->save();

        $this->applyToBox($actor, $box, $decision, $outcome, $reason, $targets);

        $effects = null;

        if ($outcome->validatesInspection()) {
            $effects = $this->occurrences->applyInspectionValidation($actor, $box, $outcome === AuditDecisionOutcome::AuthorizeContinuation);
            $this->conformity->recompute($actor, $box);
        }

        $this->journal->record('inspection_decided', $request, $actor, [
            'box' => $box->name, 'decision' => $type->code, 'outcome' => $outcome->value, 'decision_id' => $decision->id,
            'reason' => $decision->reason, 'targets' => $targets, 'proofs' => $proofs, 'occurrences' => $effects,
        ]);

        return $decision;
    }

    /**
     * @param  array<string, bool>  $proofs
     *
     * @throws WorkflowException
     */
    private function decideProofs(User $actor, AuditValidationRequest $request, AuditDecisionOutcome $outcome, array $proofs): void
    {
        $missing = AuditMissingProof::query()->where('audit_submission_id', $request->audit_submission_id)->get();

        if ($outcome->validatesInspection()) {
            $undecided = $missing->reject(fn (AuditMissingProof $proof) => array_key_exists($proof->folder->value, $proofs));

            if ($undecided->isNotEmpty()) {
                throw new WorkflowException('proofs_undecided', 422, ['folders' => $undecided->map(fn (AuditMissingProof $proof) => $proof->folder->value)->values()->all()]);
            }

            $refused = $missing->filter(fn (AuditMissingProof $proof) => $proofs[$proof->folder->value] === false);

            if ($refused->isNotEmpty() && $outcome === AuditDecisionOutcome::Validate) {
                throw new WorkflowException('proof_refused', 422, ['folders' => $refused->map(fn (AuditMissingProof $proof) => $proof->folder->value)->values()->all()]);
            }
        }

        foreach ($missing as $proof) {
            if (array_key_exists($proof->folder->value, $proofs)) {
                $proof->forceFill(['is_accepted' => (bool) $proofs[$proof->folder->value], 'decided_by' => $actor->id, 'decided_at' => now()])->save();
            }
        }
    }

    /**
     * @param  list<string>  $targets
     */
    private function applyToBox(User $actor, AuditBox $box, AuditDecision $decision, AuditDecisionOutcome $outcome, string $reason, array $targets): void
    {
        match ($outcome) {
            AuditDecisionOutcome::Validate => $box->forceFill(['inspection_state' => AuditInspectionState::Validated, 'are_reserves_authorized' => false, 'is_continuation_authorized' => false]),
            AuditDecisionOutcome::ValidateWithReserves => $box->forceFill(['inspection_state' => AuditInspectionState::Validated, 'are_reserves_authorized' => true, 'is_continuation_authorized' => false]),
            AuditDecisionOutcome::AuthorizeContinuation => $box->forceFill(['inspection_state' => AuditInspectionState::Validated, 'is_continuation_authorized' => true]),
            AuditDecisionOutcome::Refuse => $box->forceFill(['inspection_state' => AuditInspectionState::Refused]),
            AuditDecisionOutcome::RequestControl => $box->forceFill(['inspection_state' => AuditInspectionState::ControlRequested]),
            default => throw new WorkflowException('wrong_kind', 409),
        };
        $box->save();

        if ($outcome === AuditDecisionOutcome::Refuse) {
            $this->message($actor, $box, $decision, AuditBoxMessageKind::Chat, implode(' ; ', $targets).($reason === '' ? '' : ' ('.$reason.')'), false);
        }

        if ($outcome === AuditDecisionOutcome::RequestControl) {
            $this->message($actor, $box, $decision, AuditBoxMessageKind::ControlRequest, $reason, true);
        }
    }

    private function message(User $actor, AuditBox $box, AuditDecision $decision, AuditBoxMessageKind $kind, string $body, bool $blocking): void
    {
        AuditBoxMessage::query()->create([
            'audit_box_id' => $box->id, 'author_id' => $actor->id, 'kind' => $kind, 'body' => mb_substr($body, 0, 5000),
            'is_blocking' => $blocking, 'audit_decision_id' => $decision->id,
        ]);
    }

    private function alreadyDecided(AuditValidationRequest $request): WorkflowException
    {
        $decision = AuditDecision::query()->where('audit_validation_request_id', $request->id)->with(['decider', 'type'])->first();

        return new WorkflowException('already_decided', 409, [
            'by' => $decision?->decider->name,
            'at' => $decision?->decided_at->toIso8601String(),
            'decision' => $decision?->type->displayLabel(),
        ]);
    }
}
