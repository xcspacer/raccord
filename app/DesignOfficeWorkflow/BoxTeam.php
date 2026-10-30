<?php

namespace App\DesignOfficeWorkflow;

use App\Enums\AuditInspectionState;
use App\Enums\AuditRepairState;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Who works on a box in the design office workflow. The first technician to start a box becomes its responsible (the
 * box row is locked, so two technicians starting together get one responsible and one participant); the others join
 * as participants. A responsible technician cannot start another box while one of theirs waits for the design office
 * or has to be done again; their partner can. « Passer la main » gives the box to a participant, or asks a supervisor to
 * name one; it never interrupts the review nor restarts its clock, and nothing is ever deduced from a lost network.
 */
class BoxTeam
{
    public function __construct(private WorkflowGuard $guard, private WorkflowJournal $journal) {}

    /**
     * @return array{responsible_id: int, joined: bool}
     *
     * @throws WorkflowException
     */
    public function claim(User $technician, AuditBox $box): array
    {
        return DB::transaction(function () use ($technician, $box): array {
            $box = $this->locked($box);
            $this->guard->ensureOpen($box->audit);
            $this->guard->ensureBoxInService($box);

            if ($box->responsible_id !== null) {
                if ($box->responsible_id !== $technician->id && $this->join($box, $technician)) {
                    $this->journal->record('box_joined', $box, $technician, ['box' => $box->name, 'responsible_id' => $box->responsible_id]);
                }

                return ['responsible_id' => $box->responsible_id, 'joined' => $box->responsible_id !== $technician->id];
            }

            $this->ensureNotBlocked($technician, $box);

            $box->participants()->detach($technician->id);
            $box->forceFill([
                'responsible_id' => $technician->id,
                'responsible_since' => now(),
                'handover_requested_at' => null,
                'inspection_state' => $box->inspection_state === AuditInspectionState::ToDo ? AuditInspectionState::InProgress : $box->inspection_state,
            ])->save();
            $this->lockWorkflow($box->audit);
            $this->journal->record('box_claimed', $box, $technician, ['box' => $box->name]);

            return ['responsible_id' => $technician->id, 'joined' => false];
        });
    }

    /**
     * Hand the box to a participant (the one chosen, else the first active technician who joined it); without any,
     * the supervisors are asked to name a replacement and the responsible stays until they do.
     *
     * @return array{responsible_id: int|null}
     *
     * @throws WorkflowException
     */
    public function handOver(User $technician, AuditBox $box, ?int $toUserId = null): array
    {
        return DB::transaction(function () use ($technician, $box, $toUserId): array {
            $box = $this->locked($box);
            $this->guard->ensureOpen($box->audit);

            if ($box->responsible_id !== $technician->id) {
                throw new WorkflowException('not_responsible', 403);
            }

            $participants = $box->participants()->with('roles')->whereKeyNot($technician->id)->orderBy('audit_box_participants.id')->get()
                ->filter(fn (User $user) => WorkflowRoles::isTechnician($user) && ! $user->hasDeparted());
            $target = $toUserId === null ? $participants->first() : $participants->firstWhere('id', $toUserId);

            if ($toUserId !== null && $target === null) {
                throw new WorkflowException('not_participant', 409);
            }

            if ($target === null) {
                $box->forceFill(['handover_requested_at' => now()])->save();
                $this->journal->record('box_handover_requested', $box, $technician, ['box' => $box->name]);

                return ['responsible_id' => null];
            }

            $this->transfer($box, $technician, $target);
            $this->journal->record('box_handed_over', $box, $technician, ['box' => $box->name, 'old' => $technician->id, 'new' => $target->id]);

            return ['responsible_id' => $target->id];
        });
    }

    /**
     * A supervisor names the responsible of a box: a technician of its team or any other active technician.
     *
     * @throws WorkflowException
     */
    public function designate(User $supervisor, AuditBox $box, User $technician): void
    {
        DB::transaction(function () use ($supervisor, $box, $technician): void {
            $box = $this->locked($box);
            $this->guard->ensureOpen($box->audit);

            if (! WorkflowRoles::isTechnician($technician) || $technician->hasDeparted()) {
                throw new WorkflowException('not_a_technician', 422);
            }

            $previous = $box->responsible_id;

            if ($previous === $technician->id) {
                $box->forceFill(['handover_requested_at' => null])->save();

                return;
            }

            $current = $previous === null ? null : User::query()->find($previous);
            $this->transfer($box, $current, $technician);
            $this->lockWorkflow($box->audit);
            $this->journal->record('box_responsible_designated', $box, $supervisor, ['box' => $box->name, 'old' => $previous, 'new' => $technician->id]);
        });
    }

    /**
     * The first box of the technician that waits for the design office or must be done again (other than this one).
     */
    public function blockingBoxOf(User $technician, ?AuditBox $except = null): ?AuditBox
    {
        return AuditBox::query()
            ->where('responsible_id', $technician->id)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->id))
            ->where(fn ($query) => $query
                ->whereIn('inspection_state', AuditInspectionState::blocking())
                ->orWhereIn('repair_state', AuditRepairState::blocking()))
            ->whereHas('audit', fn ($audits) => $audits->whereNull('closed_at'))
            ->orderBy('id')
            ->first();
    }

    /**
     * @throws WorkflowException
     */
    public function ensureNotBlocked(User $technician, AuditBox $box): void
    {
        $blocking = $this->blockingBoxOf($technician, $box);

        if ($blocking !== null) {
            $state = in_array($blocking->repair_state, AuditRepairState::blocking(), true) ? 'repair_'.$blocking->repair_state->value : $blocking->inspection_state->value;

            throw new WorkflowException('blocked_by_pending', 409, ['box' => $blocking->name, 'state' => $state]);
        }
    }

    private function transfer(AuditBox $box, ?User $from, User $to): void
    {
        $box->participants()->detach($to->id);

        if ($from !== null) {
            $this->join($box, $from);
        }

        $box->forceFill(['responsible_id' => $to->id, 'responsible_since' => now(), 'handover_requested_at' => null])->save();
    }

    /**
     * @return bool Whether the technician was not a participant yet.
     */
    private function join(AuditBox $box, User $technician): bool
    {
        return DB::table('audit_box_participants')->insertOrIgnore(['audit_box_id' => $box->id, 'user_id' => $technician->id, 'joined_at' => now()]) > 0;
    }

    private function lockWorkflow(Audit $audit): void
    {
        if ($audit->workflow_locked_at === null) {
            $audit->forceFill(['workflow_locked_at' => now()])->save();
        }
    }

    private function locked(AuditBox $box): AuditBox
    {
        return AuditBox::query()->with('audit')->lockForUpdate()->findOrFail($box->id);
    }
}
