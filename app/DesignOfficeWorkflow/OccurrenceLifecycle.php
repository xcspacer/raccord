<?php

namespace App\DesignOfficeWorkflow;

use App\Enums\AuditAnomalyNature;
use App\Enums\AuditInspectionState;
use App\Enums\AuditInterventionStatus;
use App\Enums\AuditOccurrencePhotoRole;
use App\Enums\AuditOccurrenceSource;
use App\Enums\AuditOccurrenceState;
use App\Enums\AuditRepairState;
use App\Enums\AuditWorkflowPhase;
use App\Models\Audit;
use App\Models\AuditAnomalyType;
use App\Models\AuditBox;
use App\Models\AuditIntervention;
use App\Models\AuditOccurrence;
use App\Models\AuditOccurrenceBox;
use App\Models\AuditPhoto;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Life of an occurrence (one distinct anomaly) on the field side. A member of the box team (or a supervisor) declares
 * it with a type of the catalogue, a description and at least one « before » photo of the box — never while the box
 * waits for the design office; it is numbered OC-<cabinet>-nnnn per audit. Photos are linked to it in a role without
 * copying the file. An optical anomaly that cannot be corrected now is put on hold with a written justification; a
 * correction states the action done and has at least one « after » photo. Mechanical anomalies are corrected in
 * phase 2 only, during a planned intervention the person belongs to. A box that conformed no longer does once an
 * occurrence is confirmed on it (Conformity). Every change goes to the workflow journal.
 */
class OccurrenceLifecycle
{
    public const int MIN_DESCRIPTION = 3;

    public const int MIN_PENDING_JUSTIFICATION = 10;

    public const int MIN_ACTION = 3;

    public function __construct(private WorkflowGuard $guard, private WorkflowJournal $journal, private LegacyBoxStatus $legacyStatus, private Conformity $conformity) {}

    /**
     * @param  list<int>  $beforePhotoIds
     *
     * @throws WorkflowException
     */
    public function create(User $actor, AuditBox $box, int $typeId, string $description, array $beforePhotoIds, ?string $locationNote = null, ?string $operationId = null): AuditOccurrence
    {
        if ($operationId !== null && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $operationId) !== 1) {
            throw new WorkflowException('operation_id_required');
        }

        return DB::transaction(function () use ($actor, $box, $typeId, $description, $beforePhotoIds, $locationNote, $operationId): AuditOccurrence {
            $box = AuditBox::query()->with('audit')->lockForUpdate()->findOrFail($box->id);
            $this->guard->ensureOpen($box->audit);

            if ($operationId !== null) {
                $repeat = AuditOccurrence::query()->where('created_by', $actor->id)->where('client_operation_id', $operationId)->first();

                if ($repeat !== null) {
                    return $repeat->audit_box_id === $box->id ? $repeat : throw new WorkflowException('operation_id_reused', 409);
                }
            }

            $this->guard->ensureBoxInService($box);
            $this->ensureTeam($actor, [$box]);
            $this->ensureNotInValidation($box);

            $type = AuditAnomalyType::query()->whereKey($typeId)->where('is_active', true)->first() ?? throw new WorkflowException('bad_anomaly_type');
            $description = trim($description);

            if (mb_strlen($description) < self::MIN_DESCRIPTION) {
                throw new WorkflowException('description_required');
            }

            $beforePhotoIds = array_values(array_unique(array_map(intval(...), $beforePhotoIds)));

            if ($beforePhotoIds === []) {
                throw new WorkflowException('before_required');
            }

            if (AuditPhoto::query()->whereKey($beforePhotoIds)->where('audit_box_id', $box->id)->count() !== count($beforePhotoIds)) {
                throw new WorkflowException('bad_photo');
            }

            $audit = Audit::query()->lockForUpdate()->findOrFail($box->audit_id);
            $sequence = (int) AuditOccurrence::query()->where('audit_id', $audit->id)->max('sequence') + 1;
            $now = now();

            $occurrence = AuditOccurrence::query()->create([
                'audit_id' => $audit->id,
                'audit_box_id' => $box->id,
                'sequence' => $sequence,
                'reference' => self::reference($audit, $sequence),
                'audit_anomaly_type_id' => $type->id,
                'nature' => $type->nature,
                'state' => $audit->phase !== AuditWorkflowPhase::Inspection && $type->nature === AuditAnomalyNature::Mechanical ? AuditOccurrenceState::ToRepair : AuditOccurrenceState::Detected,
                'source' => AuditOccurrenceSource::Field,
                'description' => mb_substr($description, 0, 2000),
                'location_note' => mb_substr(trim((string) $locationNote), 0, 500) ?: null,
                'created_by' => $actor->id,
                'client_operation_id' => $operationId,
            ]);

            AuditOccurrenceBox::query()->create([
                'audit_occurrence_id' => $occurrence->id, 'audit_box_id' => $box->id, 'is_origin' => true,
                'added_by' => $actor->id, 'added_at' => $now, 'confirmed_by' => $actor->id, 'confirmed_at' => $now,
            ]);

            foreach ($beforePhotoIds as $photoId) {
                $this->attach($occurrence, $photoId, AuditOccurrencePhotoRole::Before, $actor);
            }

            $this->legacyStatus->occurrenceConfirmed($actor, $box, $occurrence);
            $this->conformity->recompute($actor, $box);
            $this->journal->record('occurrence_created', $occurrence, $actor, [
                'reference' => $occurrence->reference, 'box' => $box->name, 'nature' => $type->nature->value, 'type_id' => $type->id,
                'state' => $occurrence->state->value, 'before' => $beforePhotoIds, 'correlation_id' => $operationId,
            ]);

            return $occurrence;
        });
    }

    /**
     * Link a photo of one of the occurrence's boxes in a role (the same file then shows on the box, the occurrence and
     * the report). Linking it again changes nothing.
     *
     * @throws WorkflowException
     */
    public function linkPhoto(User $actor, AuditOccurrence $occurrence, int $photoId, AuditOccurrencePhotoRole $role): void
    {
        DB::transaction(function () use ($actor, $occurrence, $photoId, $role): void {
            $occurrence = $this->locked($occurrence);
            $this->ensureWritable($actor, $occurrence);

            if (! $this->photoOfOccurrence($occurrence, $photoId)) {
                throw new WorkflowException('bad_photo');
            }

            if ($this->attach($occurrence, $photoId, $role, $actor)) {
                $this->journal->record('occurrence_photo_linked', $occurrence, $actor, ['reference' => $occurrence->reference, 'photo_id' => $photoId, 'role' => $role->value]);
            }
        });
    }

    /**
     * An optical anomaly that cannot be corrected now, justified in writing; only the design office may then let the
     * work go on (an earlier authorisation no longer holds).
     *
     * @throws WorkflowException
     */
    public function putOnHold(User $actor, AuditOccurrence $occurrence, string $justification): void
    {
        DB::transaction(function () use ($actor, $occurrence, $justification): void {
            $occurrence = $this->locked($occurrence);
            $this->ensureWritable($actor, $occurrence);

            if ($occurrence->nature !== AuditAnomalyNature::Optical) {
                throw new WorkflowException('not_optical', 409);
            }

            if (! in_array($occurrence->state, AuditOccurrenceState::toDeal(), true)) {
                throw new WorkflowException('occurrence_bad_state', 409, ['state' => $occurrence->state->label()]);
            }

            $justification = trim($justification);

            if (mb_strlen($justification) < self::MIN_PENDING_JUSTIFICATION) {
                throw new WorkflowException('pending_justification_required');
            }

            $occurrence->forceFill([
                'pending_justification' => mb_substr($justification, 0, 2000), 'pending_by' => $actor->id, 'pending_at' => now(),
                'pending_authorized_by' => null, 'pending_authorized_at' => null,
            ])->save();
            $this->journal->record('occurrence_put_on_hold', $occurrence, $actor, ['reference' => $occurrence->reference, 'reason' => $occurrence->pending_justification]);
        });
    }

    /**
     * The anomaly was corrected: the action done, at least one « after » photo (those sent now or linked before), and
     * optionally the checks done and observations. Optical ones in phase 1; in phase 2 only during a planned
     * intervention on the box the person belongs to.
     *
     * @param  list<int>  $afterPhotoIds
     *
     * @throws WorkflowException
     */
    public function correct(User $actor, AuditOccurrence $occurrence, string $action, array $afterPhotoIds, ?string $checks = null, ?string $observations = null): void
    {
        DB::transaction(function () use ($actor, $occurrence, $action, $afterPhotoIds, $checks, $observations): void {
            $occurrence = $this->locked($occurrence);
            $this->ensureWritable($actor, $occurrence);
            $phase = $occurrence->audit->phase;

            if ($phase === AuditWorkflowPhase::Inspection) {
                if ($occurrence->nature === AuditAnomalyNature::Mechanical) {
                    throw new WorkflowException('repairs_phase_required', 409);
                }

                if (! in_array($occurrence->state, AuditOccurrenceState::toDeal(), true)) {
                    throw new WorkflowException('occurrence_bad_state', 409, ['state' => $occurrence->state->label()]);
                }
            } else {
                if (! $this->inPlannedIntervention($actor, $occurrence)) {
                    throw new WorkflowException('no_intervention', 409);
                }

                $correctable = in_array($occurrence->state, [AuditOccurrenceState::ToRepair, AuditOccurrenceState::Refused], true)
                    || ($occurrence->state === AuditOccurrenceState::Detected && $occurrence->nature === AuditAnomalyNature::Optical);

                if (! $correctable) {
                    throw new WorkflowException('occurrence_bad_state', 409, ['state' => $occurrence->state->label()]);
                }
            }

            $action = trim($action);

            if (mb_strlen($action) < self::MIN_ACTION) {
                throw new WorkflowException('action_required');
            }

            $afterPhotoIds = array_values(array_unique(array_map(intval(...), $afterPhotoIds)));

            foreach ($afterPhotoIds as $photoId) {
                if (! $this->photoOfOccurrence($occurrence, $photoId)) {
                    throw new WorkflowException('bad_photo');
                }
            }

            foreach ($afterPhotoIds as $photoId) {
                $this->attach($occurrence, $photoId, AuditOccurrencePhotoRole::After, $actor);
            }

            if (! $occurrence->photoLinks()->where('role', AuditOccurrencePhotoRole::After)->exists()) {
                throw new WorkflowException('after_required');
            }

            $before = $occurrence->state;
            $occurrence->forceFill([
                'state' => AuditOccurrenceState::Corrected,
                'action_taken' => mb_substr($action, 0, 2000),
                'checks_done' => mb_substr(trim((string) $checks), 0, 2000) ?: null,
                'observations' => mb_substr(trim((string) $observations), 0, 2000) ?: null,
                'corrected_by' => $actor->id, 'corrected_at' => now(),
                'pending_justification' => null, 'pending_by' => null, 'pending_at' => null, 'pending_authorized_by' => null, 'pending_authorized_at' => null,
            ])->save();
            $this->journal->record('occurrence_state_changed', $occurrence, $actor, [
                'reference' => $occurrence->reference, 'old' => $before->value, 'new' => $occurrence->state->value, 'action' => $occurrence->action_taken, 'after' => $afterPhotoIds,
            ]);
        });
    }

    public static function reference(Audit $audit, int $sequence): string
    {
        return 'OC-'.$audit->cabinet_key.'-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Whether the person works on one of the given boxes (responsible or participant).
     *
     * @param  iterable<AuditBox>  $boxes
     */
    public static function isTeamMember(User $user, iterable $boxes): bool
    {
        foreach ($boxes as $box) {
            if ($box->hasTeamMember($user)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Boxes the occurrence counts on (its origin and the impacted boxes confirmed and not excluded).
     *
     * @return list<AuditBox>
     */
    public static function confirmedBoxes(AuditOccurrence $occurrence): array
    {
        return AuditBox::query()->whereIn('id', $occurrence->boxLinks()->whereNotNull('confirmed_at')->whereNull('excluded_at')->select('audit_box_id'))->get()->all();
    }

    /**
     * Technicians must work on one of the boxes; supervisors may act on any; the design office never writes here.
     *
     * @param  iterable<AuditBox>  $boxes
     *
     * @throws WorkflowException
     */
    private function ensureTeam(User $actor, iterable $boxes): void
    {
        if (WorkflowRoles::isSupervisor($actor)) {
            return;
        }

        if (! WorkflowRoles::isTechnician($actor) || ! self::isTeamMember($actor, $boxes)) {
            throw new WorkflowException('not_team_member', 403);
        }
    }

    /**
     * @throws WorkflowException
     */
    private function ensureNotInValidation(AuditBox $box): void
    {
        if ($box->inspection_state === AuditInspectionState::Submitted || $box->repair_state === AuditRepairState::Submitted) {
            throw new WorkflowException('box_in_validation', 409, ['box' => $box->name]);
        }
    }

    /**
     * @throws WorkflowException
     */
    private function ensureWritable(User $actor, AuditOccurrence $occurrence): void
    {
        $this->guard->ensureOpenForOccurrence($occurrence);
        $this->ensureTeam($actor, self::confirmedBoxes($occurrence));

        if ($occurrence->state->isClosed()) {
            throw new WorkflowException('occurrence_closed', 409, ['reference' => $occurrence->reference]);
        }
    }

    private function photoOfOccurrence(AuditOccurrence $occurrence, int $photoId): bool
    {
        return AuditPhoto::query()->whereKey($photoId)
            ->whereIn('audit_box_id', $occurrence->boxLinks()->whereNull('excluded_at')->select('audit_box_id'))
            ->exists();
    }

    private function inPlannedIntervention(User $actor, AuditOccurrence $occurrence): bool
    {
        return AuditIntervention::query()
            ->where('audit_box_id', $occurrence->audit_box_id)
            ->where('status', AuditInterventionStatus::Planned)
            ->where(fn ($query) => $query->where('responsible_id', $actor->id)->orWhereHas('members', fn ($members) => $members->whereKey($actor->id)))
            ->exists();
    }

    /**
     * @return bool Whether the link is new.
     */
    private function attach(AuditOccurrence $occurrence, int $photoId, AuditOccurrencePhotoRole $role, User $actor): bool
    {
        return DB::table('audit_occurrence_photos')->insertOrIgnore([
            'audit_occurrence_id' => $occurrence->id, 'audit_photo_id' => $photoId, 'role' => $role->value, 'linked_by' => $actor->id, 'linked_at' => now(),
        ]) > 0;
    }

    private function locked(AuditOccurrence $occurrence): AuditOccurrence
    {
        return AuditOccurrence::query()->with('audit')->lockForUpdate()->findOrFail($occurrence->id);
    }
}
