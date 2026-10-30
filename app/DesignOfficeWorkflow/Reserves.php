<?php

namespace App\DesignOfficeWorkflow;

use App\Enums\AuditAnomalyNature;
use App\Enums\AuditInterventionStatus;
use App\Enums\AuditOccurrenceState;
use App\Enums\AuditRepairState;
use App\Enums\AuditReserveKind;
use App\Enums\AuditReserveProposalState;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\AuditIntervention;
use App\Models\AuditOccurrence;
use App\Models\AuditReserve;
use App\Models\AuditReserveProposal;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reserves (phase 3). The design office or a supervisor proposes a reserve on an anomaly still open; only a supervisor
 * accepts it — or refuses it with a reason shown to the proposer —, as « follow-up required » (open until a supervisor closes it once the follow-up is done) or as
 * « administrative closure » (closed at once). Accepting is never a correction: the anomaly ends as « reserve
 * accepted », every box it is confirmed on gets its reserve and becomes « conforming with accepted reserve », and a
 * planned repair visit with nothing left to repair is cancelled. A follow-up reserve can still be closed after the
 * audit is closed.
 */
class Reserves
{
    /** Shortest justification of a proposal or an acceptance. */
    public const int MIN_REASON = 10;

    /** Shortest reason to refuse a proposal. */
    public const int MIN_REFUSAL_REASON = 5;

    /** Shortest reason to close a follow-up reserve. */
    public const int MIN_CLOSING_REASON = 5;

    public function __construct(private WorkflowGuard $guard, private OccurrenceRules $occurrences, private Conformity $conformity, private WorkflowJournal $journal) {}

    /**
     * Anomalies a reserve can be proposed or accepted on: still open, with no repair waiting for its decision.
     *
     * @return list<AuditOccurrenceState>
     */
    public static function reservableStates(): array
    {
        return [AuditOccurrenceState::Detected, AuditOccurrenceState::ToRepair, AuditOccurrenceState::Refused];
    }

    /**
     * A new proposal replaces the open one of the same anomaly (one open proposal per anomaly).
     *
     * @throws WorkflowException
     */
    public function propose(User $actor, AuditOccurrence $occurrence, string $reason): AuditReserveProposal
    {
        $this->ensureReviewerOrSupervisor($actor);
        $reason = trim($reason);

        return DB::transaction(function () use ($actor, $occurrence, $reason): AuditReserveProposal {
            $occurrence = $this->lockOpen($occurrence);

            if (mb_strlen($reason) < self::MIN_REASON) {
                throw new WorkflowException('reserve_reason_required', 422, ['min' => self::MIN_REASON]);
            }

            $proposal = AuditReserveProposal::query()->where('audit_occurrence_id', $occurrence->id)->where('state', AuditReserveProposalState::Open)->first()
                ?? new AuditReserveProposal(['audit_occurrence_id' => $occurrence->id, 'state' => AuditReserveProposalState::Open]);
            $proposal->fill(['proposed_by' => $actor->id, 'reason' => mb_substr($reason, 0, 2000)])->save();

            $this->journal->record('reserve_proposed', $occurrence, $actor, ['reference' => $occurrence->reference, 'proposal_id' => $proposal->id, 'reason' => $proposal->reason]);

            return $proposal;
        });
    }

    /**
     * @return list<string> Names of the boxes that got the reserve.
     *
     * @throws WorkflowException
     */
    public function accept(User $actor, AuditOccurrence $occurrence, AuditReserveKind $kind, string $reason): array
    {
        $this->ensureSupervisor($actor);
        $reason = trim($reason);

        return DB::transaction(function () use ($actor, $occurrence, $kind, $reason): array {
            $occurrence = $this->lockOpen($occurrence);

            if (mb_strlen($reason) < self::MIN_REASON) {
                throw new WorkflowException('reserve_reason_required', 422, ['min' => self::MIN_REASON]);
            }

            $before = $occurrence->state;
            $proposal = AuditReserveProposal::query()->where('audit_occurrence_id', $occurrence->id)->where('state', AuditReserveProposalState::Open)->lockForUpdate()->first();
            $boxes = $this->confirmedBoxes($occurrence);
            $now = now();

            foreach ($boxes as $box) {
                AuditReserve::query()->create([
                    'audit_box_id' => $box->id, 'audit_occurrence_id' => $occurrence->id, 'audit_reserve_proposal_id' => $proposal?->id,
                    'kind' => $kind, 'reason' => mb_substr($reason, 0, 2000), 'accepted_by' => $actor->id, 'accepted_at' => $now,
                    'closed_by' => $kind === AuditReserveKind::AdministrativeClosure ? $actor->id : null,
                    'closed_at' => $kind === AuditReserveKind::AdministrativeClosure ? $now : null,
                ]);
            }

            $occurrence->forceFill(['state' => AuditOccurrenceState::ReserveAccepted])->save();
            $proposal?->forceFill(['state' => AuditReserveProposalState::Accepted, 'decided_by' => $actor->id, 'decided_at' => $now])->save();
            $this->journal->record('occurrence_state_changed', $occurrence, $actor, ['reference' => $occurrence->reference, 'old' => $before->value, 'new' => $occurrence->state->value, 'reason' => $reason]);

            foreach ($boxes as $box) {
                $this->releaseRepairs($actor, $box);
                $this->conformity->recompute($actor, $box);
            }

            $names = $boxes->pluck('name')->all();
            $this->journal->record('reserve_accepted', $occurrence, $actor, ['reference' => $occurrence->reference, 'kind' => $kind->value, 'boxes' => $names, 'proposal_id' => $proposal?->id, 'reason' => $reason]);

            return $names;
        });
    }

    /**
     * A supervisor refuses the open proposal of an anomaly, with a reason the proposer sees; the anomaly stays open.
     *
     * @throws WorkflowException
     */
    public function refuse(User $actor, AuditOccurrence $occurrence, string $reason): AuditReserveProposal
    {
        $this->ensureSupervisor($actor);
        $reason = trim($reason);

        return DB::transaction(function () use ($actor, $occurrence, $reason): AuditReserveProposal {
            $occurrence = AuditOccurrence::query()->with('audit')->lockForUpdate()->findOrFail($occurrence->id);
            $this->guard->ensureOpen($occurrence->audit);
            $proposal = AuditReserveProposal::query()->where('audit_occurrence_id', $occurrence->id)->where('state', AuditReserveProposalState::Open)->lockForUpdate()->first()
                ?? throw new WorkflowException('no_open_proposal', 409, ['reference' => $occurrence->reference]);

            if (mb_strlen($reason) < self::MIN_REFUSAL_REASON) {
                throw new WorkflowException('reserve_reason_required', 422, ['min' => self::MIN_REFUSAL_REASON]);
            }

            $proposal->forceFill(['state' => AuditReserveProposalState::Rejected, 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_reason' => mb_substr($reason, 0, 2000)])->save();
            $this->journal->record('reserve_refused', $occurrence, $actor, ['reference' => $occurrence->reference, 'proposal_id' => $proposal->id, 'proposed_by' => $proposal->proposed_by, 'reason' => $proposal->decision_reason]);

            return $proposal;
        });
    }

    /**
     * A supervisor closes a follow-up reserve once the follow-up is done (never a claim of technical correction).
     *
     * @throws WorkflowException
     */
    public function close(User $actor, AuditReserve $reserve, string $reason): AuditReserve
    {
        $this->ensureSupervisor($actor);
        $reason = trim($reason);

        return DB::transaction(function () use ($actor, $reserve, $reason): AuditReserve {
            $reserve = AuditReserve::query()->with('box.audit', 'occurrence')->lockForUpdate()->findOrFail($reserve->id);
            $this->guard->ensureDesignOffice($reserve->box->audit);

            if ($reserve->closed_at !== null) {
                throw new WorkflowException('reserve_already_closed', 409);
            }

            if (mb_strlen($reason) < self::MIN_CLOSING_REASON) {
                throw new WorkflowException('reserve_reason_required', 422, ['min' => self::MIN_CLOSING_REASON]);
            }

            $reserve->forceFill(['closed_by' => $actor->id, 'closed_at' => now(), 'closing_reason' => mb_substr($reason, 0, 2000)])->save();
            $this->journal->record('reserve_closed', $reserve, $actor, ['box' => $reserve->box->name, 'reference' => $reserve->occurrence?->reference, 'reason' => $reason]);

            return $reserve;
        });
    }

    /**
     * Open anomalies of an audit that a reserve can cover, with their latest proposal (open, or refused with its reason).
     *
     * @return Collection<int, AuditOccurrence>
     */
    public function openOccurrences(Audit $audit): Collection
    {
        return AuditOccurrence::query()->where('audit_id', $audit->id)->whereIn('state', self::reservableStates())
            ->with(['box', 'type', 'reserveProposals' => fn ($proposals) => $proposals->whereIn('state', [AuditReserveProposalState::Open, AuditReserveProposalState::Rejected])->latest('id')->with(['proposer', 'decider'])])
            ->orderBy('sequence')->get();
    }

    /**
     * @return Collection<int, AuditReserve>
     */
    public function accepted(Audit $audit): Collection
    {
        return AuditReserve::query()->whereHas('box', fn (Builder $boxes) => $boxes->where('audit_id', $audit->id))
            ->with(['box', 'occurrence', 'accepter', 'closer'])->orderBy('id')->get();
    }

    /**
     * @throws WorkflowException
     */
    private function lockOpen(AuditOccurrence $occurrence): AuditOccurrence
    {
        $occurrence = AuditOccurrence::query()->with('audit')->lockForUpdate()->findOrFail($occurrence->id);
        $this->guard->ensureOpen($occurrence->audit);

        if ($occurrence->state === AuditOccurrenceState::Corrected) {
            throw new WorkflowException('repair_awaiting_decision', 409, ['reference' => $occurrence->reference]);
        }

        if (! in_array($occurrence->state, self::reservableStates(), true)) {
            throw new WorkflowException('occurrence_not_open', 409, ['reference' => $occurrence->reference]);
        }

        return $occurrence;
    }

    /**
     * @return Collection<int, AuditBox>
     */
    private function confirmedBoxes(AuditOccurrence $occurrence): Collection
    {
        return AuditBox::query()->whereHas('occurrenceLinks', fn (Builder $links) => $links
            ->where('audit_occurrence_id', $occurrence->id)->whereNotNull('confirmed_at')->whereNull('excluded_at'))
            ->orderBy('name')->lockForUpdate()->get();
    }

    /**
     * Nothing left to repair on the box: its planned visit has no purpose any more.
     */
    private function releaseRepairs(User $actor, AuditBox $box): void
    {
        $left = $this->occurrences->confirmedOn($box)->where(fn (Builder $open) => $open
            ->whereIn('state', [AuditOccurrenceState::ToRepair, AuditOccurrenceState::Refused, AuditOccurrenceState::Corrected])
            ->orWhere(fn (Builder $waiting) => $waiting->where('state', AuditOccurrenceState::Detected)->where('nature', AuditAnomalyNature::Optical)->whereNotNull('pending_authorized_at')))
            ->exists();

        if ($left) {
            return;
        }

        $intervention = AuditIntervention::query()->where('audit_box_id', $box->id)->where('status', AuditInterventionStatus::Planned)->lockForUpdate()->first();

        if ($intervention !== null) {
            $intervention->forceFill(['status' => AuditInterventionStatus::Cancelled, 'cancelled_by' => $actor->id, 'cancelled_at' => now(), 'closed_at' => now()])->save();
            $this->journal->record('intervention_cancelled', $intervention, $actor, ['box' => $box->name, 'cause' => 'reserve_accepted']);
        }

        if (in_array($box->repair_state, [AuditRepairState::Planned, AuditRepairState::Refused], true)) {
            $box->forceFill(['repair_state' => AuditRepairState::None])->save();
        }
    }

    /**
     * @throws WorkflowException
     */
    private function ensureReviewerOrSupervisor(User $actor): void
    {
        if (! WorkflowRoles::isSupervisor($actor) && ! WorkflowRoles::isReviewer($actor)) {
            throw new WorkflowException('forbidden', 403);
        }
    }

    /**
     * @throws WorkflowException
     */
    private function ensureSupervisor(User $actor): void
    {
        if (! WorkflowRoles::isSupervisor($actor)) {
            throw new WorkflowException('supervisor_only', 403);
        }
    }
}
