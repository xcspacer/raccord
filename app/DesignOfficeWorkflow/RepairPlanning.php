<?php

namespace App\DesignOfficeWorkflow;

use App\Enums\AuditAnomalyNature;
use App\Enums\AuditInspectionState;
use App\Enums\AuditInterventionStatus;
use App\Enums\AuditOccurrenceState;
use App\Enums\AuditRepairState;
use App\Enums\AuditRequestState;
use App\Enums\AuditWorkflowPhase;
use App\Enums\Role;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\AuditIntervention;
use App\Models\AuditOccurrence;
use App\Models\AuditValidationRequest;
use App\Models\User;
use App\Support\FeatureAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Phase 2 of the design office workflow (mechanical repairs), as the supervisors run it. The phase opens once no
 * validation request of the audit is still open, and never goes back. The boxes to repair are those whose validated
 * inspection left occurrences to repair (mechanical ones qualified at the inspection, refused repairs, optical
 * anomalies allowed to wait). A supervisor plans one intervention at a time per box — a date and a team of active
 * technicians who may work on the audit, its responsible first — which becomes the team of the box (during phase 2 a
 * repair is only recorded inside a planned intervention the person belongs to: OccurrenceLifecycle::correct); a planned
 * intervention is cancelled with a reason. Once the audit is closed, only the boxes with an occurrence declared by the
 * client still get (or lose) an intervention (WorkflowGuard::ensureOpenOrClientRepair).
 */
class RepairPlanning
{
    /** Shortest reason accepted to cancel an intervention. */
    public const int MIN_REASON = 3;

    public function __construct(private WorkflowGuard $guard, private OccurrenceRules $occurrences, private WorkflowJournal $journal, private FeatureAccess $access) {}

    /**
     * Occurrences still to repair in phase 2.
     *
     * @param  Builder<AuditOccurrence>  $occurrences
     * @return Builder<AuditOccurrence>
     */
    public static function repairable(Builder $occurrences): Builder
    {
        return $occurrences->where(fn (Builder $query) => $query
            ->whereIn('state', [AuditOccurrenceState::ToRepair, AuditOccurrenceState::Refused])
            ->orWhere(fn (Builder $optical) => $optical
                ->where('state', AuditOccurrenceState::Detected)
                ->where('nature', AuditAnomalyNature::Optical)
                ->whereNotNull('pending_authorized_at')));
    }

    /**
     * The supervisor opens phase 2: only from phase 1 and once every validation request of the audit is decided.
     *
     * @return array{boxes: int, occurrences: int}
     *
     * @throws WorkflowException
     */
    public function openPhase(User $supervisor, Audit $audit): array
    {
        return DB::transaction(function () use ($supervisor, $audit): array {
            $audit = Audit::query()->lockForUpdate()->findOrFail($audit->id);
            $this->guard->ensureOpen($audit);
            $this->ensureSupervisor($supervisor);

            if ($audit->phase !== AuditWorkflowPhase::Inspection) {
                throw new WorkflowException('wrong_phase', 409, ['phase' => $audit->phase->label()]);
            }

            $open = AuditValidationRequest::query()->whereIn('state', AuditRequestState::open())
                ->whereHas('box', fn (Builder $boxes) => $boxes->where('audit_id', $audit->id))->count();

            if ($open > 0) {
                throw new WorkflowException('requests_open', 409, ['count' => $open]);
            }

            $audit->forceFill(['phase' => AuditWorkflowPhase::Repairs])->save();
            $pool = $this->pool($audit);
            $summary = ['boxes' => $pool->count(), 'occurrences' => $pool->sum(fn (AuditBox $box) => $box->getAttribute('repairs')->count())];
            $this->journal->record('repair_phase_opened', $audit, $supervisor, ['cabinet' => $audit->cabinet_key, 'old' => AuditWorkflowPhase::Inspection->value, 'new' => AuditWorkflowPhase::Repairs->value, ...$summary]);

            return $summary;
        });
    }

    /**
     * Boxes of the audit with something to repair, each with its repairs (attribute `repairs`) and its open
     * intervention (attribute `open_intervention`), by name.
     *
     * @return Collection<int, AuditBox>
     */
    public function pool(Audit $audit): Collection
    {
        $occurrences = self::repairable(AuditOccurrence::query()->where('audit_id', $audit->id))
            ->with(['boxLinks' => fn ($links) => $links->whereNotNull('confirmed_at')->whereNull('excluded_at'), 'type'])
            ->orderBy('id')->get();
        $byBox = [];

        foreach ($occurrences as $occurrence) {
            foreach ($occurrence->boxLinks as $link) {
                $byBox[$link->audit_box_id][] = $occurrence;
            }
        }

        $interventions = AuditIntervention::query()->whereIn('audit_box_id', array_keys($byBox))
            ->whereIn('status', [AuditInterventionStatus::Planned, AuditInterventionStatus::Submitted])
            ->with(['responsible', 'members'])->get()->keyBy('audit_box_id');

        return AuditBox::query()->whereIn('id', array_keys($byBox))->whereNull('removed_at')->orderBy('name')->get()
            ->each(function (AuditBox $box) use ($byBox, $interventions): void {
                $box->setAttribute('repairs', new Collection($byBox[$box->id]));
                $box->setAttribute('open_intervention', $interventions->get($box->id));
            });
    }

    /**
     * The supervisor plans the repair intervention of a box. Its team (the responsible and the other members) becomes
     * the team of the box.
     *
     * @param  list<int>  $memberIds  The other technicians of the team.
     *
     * @throws WorkflowException
     */
    public function plan(User $supervisor, AuditBox $box, string $plannedOn, int $responsibleId, array $memberIds = [], ?string $note = null): AuditIntervention
    {
        $date = $this->date($plannedOn);

        try {
            return DB::transaction(fn (): AuditIntervention => $this->createIntervention($supervisor, $box, $date, $responsibleId, $memberIds, $note));
        } catch (UniqueConstraintViolationException) {
            throw new WorkflowException('intervention_exists', 409);
        }
    }

    /**
     * Only a planned intervention (not submitted yet) is cancelled, with a reason; the box goes back to the pool.
     *
     * @throws WorkflowException
     */
    public function cancel(User $supervisor, AuditIntervention $intervention, string $reason): void
    {
        DB::transaction(function () use ($supervisor, $intervention, $reason): void {
            $intervention = AuditIntervention::query()->lockForUpdate()->findOrFail($intervention->id);
            $box = AuditBox::query()->with('audit')->lockForUpdate()->findOrFail($intervention->audit_box_id);
            $this->guard->ensureOpenOrClientRepair($box->audit, $box);
            $this->ensureSupervisor($supervisor);

            if ($intervention->status !== AuditInterventionStatus::Planned) {
                throw new WorkflowException('intervention_not_planned', 409, ['status' => $intervention->status->label()]);
            }

            $reason = trim($reason);

            if (mb_strlen($reason) < self::MIN_REASON) {
                throw new WorkflowException('cancellation_reason_required');
            }

            $intervention->forceFill([
                'status' => AuditInterventionStatus::Cancelled, 'cancelled_by' => $supervisor->id, 'cancelled_at' => now(),
                'cancellation_reason' => mb_substr($reason, 0, 2000), 'closed_at' => now(),
            ])->save();

            if (in_array($box->repair_state, [AuditRepairState::Planned, AuditRepairState::Refused], true)) {
                $box->forceFill(['repair_state' => AuditRepairState::None])->save();
            }

            $this->journal->record('intervention_cancelled', $intervention, $supervisor, ['box' => $box->name, 'reason' => $intervention->cancellation_reason]);
        });
    }

    /**
     * Open interventions of a technician (as a member of their team), soonest first. A closed audit only keeps open
     * interventions for the repair of occurrences its client declared (the closure requires none open).
     *
     * @return Collection<int, AuditIntervention>
     */
    public function interventionsOf(User $technician): Collection
    {
        return AuditIntervention::query()
            ->whereIn('status', [AuditInterventionStatus::Planned, AuditInterventionStatus::Submitted])
            ->whereHas('members', fn (Builder $members) => $members->whereKey($technician->id))
            ->with(['audit', 'box', 'responsible', 'members'])
            ->orderBy('planned_on')->orderBy('id')
            ->get();
    }

    /**
     * Repairs of a box still to do, or done and waiting to be submitted or decided.
     *
     * @return Collection<int, AuditOccurrence>
     */
    public function openRepairs(AuditBox $box): Collection
    {
        return $this->occurrences->confirmedOn($box)
            ->where(fn (Builder $query) => self::repairable($query)->orWhere('state', AuditOccurrenceState::Corrected))
            ->with('type')->orderBy('id')->get();
    }

    /**
     * Active technicians who may take part in an intervention on the audit (they can open it and use the workflow).
     *
     * @return Collection<int, User>
     */
    public function eligibleTechnicians(Audit $audit): Collection
    {
        return User::query()->role(Role::Technician->value)
            ->where(fn ($query) => $query->whereNull('departure_date')->orWhere('departure_date', '>', now()->toDateString()))
            ->with(['roles', 'agency'])->orderBy('name')->get()
            ->filter(fn (User $user) => $this->mayJoin($user, $audit))
            ->values();
    }

    /**
     * @param  list<int>  $memberIds
     */
    private function createIntervention(User $supervisor, AuditBox $box, CarbonImmutable $date, int $responsibleId, array $memberIds, ?string $note): AuditIntervention
    {
        $box = AuditBox::query()->with('audit')->lockForUpdate()->findOrFail($box->id);
        $this->guard->ensureOpenOrClientRepair($box->audit, $box);
        $this->ensureSupervisor($supervisor);
        $this->guard->ensureBoxInService($box);

        if ($box->audit->phase === AuditWorkflowPhase::Inspection) {
            throw new WorkflowException('repairs_phase_not_open', 409);
        }

        if ($box->inspection_state !== AuditInspectionState::Validated) {
            throw new WorkflowException('inspection_not_validated', 409, ['box' => $box->name]);
        }

        if ($box->repair_state === AuditRepairState::Submitted) {
            throw new WorkflowException('repairs_under_review', 409, ['box' => $box->name]);
        }

        $repairs = self::repairable($this->occurrences->confirmedOn($box))->count();

        if ($repairs === 0) {
            throw new WorkflowException('nothing_to_repair', 409, ['box' => $box->name]);
        }

        $team = array_values(array_unique([$responsibleId, ...array_map(intval(...), $memberIds)]));
        $people = User::query()->whereIn('id', $team)->with(['roles', 'agency'])->get();

        if ($people->count() !== count($team) || $people->contains(fn (User $user) => ! $this->mayJoin($user, $box->audit))) {
            throw new WorkflowException('invalid_team');
        }

        if (AuditIntervention::query()->where('audit_box_id', $box->id)->whereIn('status', [AuditInterventionStatus::Planned, AuditInterventionStatus::Submitted])->exists()) {
            throw new WorkflowException('intervention_exists', 409);
        }

        $note = trim((string) $note);
        $intervention = AuditIntervention::query()->create([
            'audit_id' => $box->audit_id, 'audit_box_id' => $box->id, 'planned_on' => $date->toDateString(), 'responsible_id' => $responsibleId,
            'note' => $note === '' ? null : mb_substr($note, 0, 2000), 'status' => AuditInterventionStatus::Planned, 'created_by' => $supervisor->id,
        ]);
        $intervention->members()->attach($team);

        $box->participants()->detach();

        foreach (array_diff($team, [$responsibleId]) as $memberId) {
            DB::table('audit_box_participants')->insert(['audit_box_id' => $box->id, 'user_id' => $memberId, 'joined_at' => now()]);
        }

        $box->forceFill([
            'responsible_id' => $responsibleId, 'responsible_since' => now(), 'handover_requested_at' => null, 'repair_state' => AuditRepairState::Planned,
        ])->save();

        $this->journal->record('intervention_planned', $intervention, $supervisor, [
            'box' => $box->name, 'planned_on' => $date->toDateString(), 'responsible_id' => $responsibleId, 'team' => $team, 'repairs' => $repairs,
        ]);

        return $intervention;
    }

    private function mayJoin(User $user, Audit $audit): bool
    {
        return WorkflowRoles::isTechnician($user)
            && ! $user->hasDeparted()
            && $this->access->allowsDesignOfficeWorkflow($user)
            && Gate::forUser($user)->allows('work', $audit);
    }

    /**
     * @throws WorkflowException
     */
    private function date(string $value): CarbonImmutable
    {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $value) : null;

        if (! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== $value) {
            throw new WorkflowException('invalid_date');
        }

        return $date;
    }

    /**
     * @throws WorkflowException
     */
    private function ensureSupervisor(User $user): void
    {
        if (! WorkflowRoles::isSupervisor($user)) {
            throw new WorkflowException('forbidden', 403);
        }
    }
}
