<?php

namespace App\DesignOfficeWorkflow;

use App\Enums\AuditBoxMessageKind;
use App\Enums\AuditConformity;
use App\Enums\AuditDecisionOutcome;
use App\Enums\AuditInterventionStatus;
use App\Enums\AuditOccurrenceState;
use App\Enums\AuditRepairState;
use App\Enums\AuditRequestState;
use App\Enums\AuditSubmissionKind;
use App\Models\AuditBox;
use App\Models\AuditBoxMessage;
use App\Models\AuditDecision;
use App\Models\AuditDecisionType;
use App\Models\AuditIntervention;
use App\Models\AuditOccurrence;
use App\Models\AuditRepairItem;
use App\Models\AuditValidationRequest;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The design office decides a repairs request (phase 2), once, repair by repair: every repair of the submission is
 * accepted or refused (with a reason), each exactly once. The global decision follows: repairs validated, partly
 * validated or refused. An accepted repair is validated for good (a refused one never undoes it); refused ones go back
 * to the same team, whose intervention is planned again, and only they are submitted again. Once nothing is refused
 * and nothing else is open on the box, its repairs are validated and its conformity derived. The first decision
 * recorded wins; times are the server's.
 */
class DecideRepairs
{
    /** Shortest reason accepted to refuse a repair. */
    public const int MIN_REASON = 3;

    public function __construct(
        private WorkflowGuard $guard,
        private OccurrenceRules $occurrences,
        private Conformity $conformity,
        private ReviewQueue $queue,
        private WorkflowJournal $journal,
    ) {}

    /**
     * @param  array<int, array{accepted: bool, reason?: string|null}>  $verdicts  Verdict per occurrence id of the submission.
     * @return array{decision: AuditDecision, accepted: int, refused: int, conformity: AuditConformity}
     *
     * @throws WorkflowException
     */
    public function handle(User $actor, AuditValidationRequest $request, array $verdicts, ?string $reason = null): array
    {
        try {
            $result = DB::transaction(fn (): array => $this->decide($actor, $request, $verdicts, trim((string) $reason)));
        } catch (UniqueConstraintViolationException) {
            throw $this->alreadyDecided($request);
        }

        $this->queue->dispatch();

        return $result;
    }

    /**
     * @param  array<int, array{accepted: bool, reason?: string|null}>  $verdicts
     * @return array{decision: AuditDecision, accepted: int, refused: int, conformity: AuditConformity}
     */
    private function decide(User $actor, AuditValidationRequest $request, array $verdicts, string $reason): array
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

        if ($request->submission->kind !== AuditSubmissionKind::Repair) {
            throw new WorkflowException('not_repairs', 409);
        }

        $expected = $this->expected($request);
        $verdicts = $this->verdicts($expected, $verdicts);
        $refused = array_filter($verdicts, fn (array $verdict) => ! $verdict['accepted']);
        $acceptedCount = count($verdicts) - count($refused);
        $outcome = match (true) {
            $refused === [] => AuditDecisionOutcome::RepairsValidated,
            $acceptedCount === 0 => AuditDecisionOutcome::RepairsRefused,
            default => AuditDecisionOutcome::RepairsPartial,
        };
        $type = AuditDecisionType::query()->where('outcome', $outcome)->where('submission_kind', AuditSubmissionKind::Repair)->where('is_active', true)
            ->orderBy('position')->orderBy('id')->first() ?? throw new WorkflowException('unknown_decision');

        $summary = $reason !== '' ? $reason : implode(' ; ', array_map(fn (int $id) => $expected[$id].' : '.$refused[$id]['reason'], array_keys($refused)));
        $decision = AuditDecision::query()->create([
            'audit_validation_request_id' => $request->id,
            'audit_decision_type_id' => $type->id,
            'decided_by' => $actor->id,
            'reason' => $summary === '' ? null : mb_substr($summary, 0, 2000),
            'decided_at' => now(),
        ]);
        $request->forceFill(['state' => AuditRequestState::Decided, 'decided_at' => $decision->decided_at])->save();

        $this->applyToOccurrences($actor, $box, $decision, $expected, $verdicts);
        $conformity = $this->applyToBox($actor, $box, $refused !== []);

        $this->journal->record('repairs_decided', $request, $actor, [
            'box' => $box->name, 'decision' => $type->code, 'outcome' => $outcome->value, 'decision_id' => $decision->id,
            'accepted' => $acceptedCount, 'refused' => count($refused), 'reason' => $decision->reason, 'conformity' => $conformity->value,
        ]);

        return ['decision' => $decision, 'accepted' => $acceptedCount, 'refused' => count($refused), 'conformity' => $conformity];
    }

    /**
     * Repairs of the submission: occurrence id => reference.
     *
     * @return array<int, string>
     */
    private function expected(AuditValidationRequest $request): array
    {
        /** @var list<array{id: int, reference: string}> $items */
        $items = $request->submission->snapshot['items'] ?? [];
        $expected = [];

        foreach ($items as $item) {
            $expected[(int) $item['id']] = (string) $item['reference'];
        }

        return $expected;
    }

    /**
     * Every repair decided exactly once, each refusal with its reason.
     *
     * @param  array<int, string>  $expected
     * @param  array<int, array{accepted: bool, reason?: string|null}>  $verdicts
     * @return array<int, array{accepted: bool, reason: string|null}>
     *
     * @throws WorkflowException
     */
    private function verdicts(array $expected, array $verdicts): array
    {
        $keys = array_map(intval(...), array_keys($verdicts));
        sort($keys);
        $wanted = array_keys($expected);
        sort($wanted);

        if ($keys !== $wanted) {
            throw new WorkflowException('items_incomplete', 422, ['references' => array_values(array_diff_key($expected, array_flip($keys)))]);
        }

        $clean = [];

        foreach ($verdicts as $id => $verdict) {
            $accepted = (bool) $verdict['accepted'];
            $text = trim((string) ($verdict['reason'] ?? ''));

            if (! $accepted && mb_strlen($text) < self::MIN_REASON) {
                throw new WorkflowException('repair_reason_required', 422, ['reference' => $expected[(int) $id]]);
            }

            $clean[(int) $id] = ['accepted' => $accepted, 'reason' => $accepted ? null : mb_substr($text, 0, 2000)];
        }

        return $clean;
    }

    /**
     * @param  array<int, string>  $expected
     * @param  array<int, array{accepted: bool, reason: string|null}>  $verdicts
     */
    private function applyToOccurrences(User $actor, AuditBox $box, AuditDecision $decision, array $expected, array $verdicts): void
    {
        $occurrences = AuditOccurrence::query()->whereIn('id', array_keys($verdicts))->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        foreach ($verdicts as $id => $verdict) {
            AuditRepairItem::query()->create(['audit_decision_id' => $decision->id, 'audit_occurrence_id' => $id, 'is_accepted' => $verdict['accepted'], 'reason' => $verdict['reason']]);
            $occurrence = $occurrences->get($id);

            if ($occurrence === null || $occurrence->state !== AuditOccurrenceState::Corrected) {
                continue;
            }

            $before = $occurrence->state;
            $occurrence->forceFill($verdict['accepted']
                ? ['state' => AuditOccurrenceState::Validated, 'validated_by' => $actor->id, 'validated_at' => now()]
                : ['state' => AuditOccurrenceState::Refused])->save();

            if (! $verdict['accepted']) {
                AuditBoxMessage::query()->create([
                    'audit_box_id' => $box->id, 'author_id' => $actor->id, 'kind' => AuditBoxMessageKind::Chat,
                    'body' => mb_substr($expected[$id].' — '.$verdict['reason'], 0, 5000), 'is_blocking' => false, 'audit_decision_id' => $decision->id,
                ]);
            }

            $this->journal->record('occurrence_state_changed', $occurrence, $actor, [
                'reference' => $occurrence->reference, 'old' => $before->value, 'new' => $occurrence->state->value, 'decision_id' => $decision->id, 'reason' => $verdict['reason'],
            ]);
        }
    }

    /**
     * Refused repairs go back to the same team (its intervention is planned again); otherwise the intervention is
     * completed and the repairs of the box are validated once nothing else is open on it.
     */
    private function applyToBox(User $actor, AuditBox $box, bool $anyRefused): AuditConformity
    {
        $intervention = AuditIntervention::query()->where('audit_box_id', $box->id)->where('status', AuditInterventionStatus::Submitted)->lockForUpdate()->first();

        if ($anyRefused) {
            $intervention?->forceFill(['status' => AuditInterventionStatus::Planned])->save();
            $box->forceFill(['repair_state' => AuditRepairState::Refused])->save();

            return $box->conformity;
        }

        $intervention?->forceFill(['status' => AuditInterventionStatus::Completed, 'closed_at' => now()])->save();
        $open = $this->occurrences->confirmedOn($box)
            ->whereIn('state', [AuditOccurrenceState::Detected, AuditOccurrenceState::ToRepair, AuditOccurrenceState::Corrected, AuditOccurrenceState::Refused])
            ->exists();
        $box->forceFill(['repair_state' => $open ? AuditRepairState::None : AuditRepairState::Validated])->save();

        return $this->conformity->recompute($actor, $box);
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
