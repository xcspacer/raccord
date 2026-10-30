<?php

namespace App\DesignOfficeWorkflow;

use App\Enums\AuditAnomalyNature;
use App\Enums\AuditOccurrencePhotoRole;
use App\Enums\AuditOccurrenceState;
use App\Models\AuditBox;
use App\Models\AuditOccurrence;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * What the occurrences of a box mean for its inspection (phase 1): which optical anomalies are still to be dealt
 * with, what the submission freezes about them, and what a validated inspection does to them. Only occurrences
 * confirmed on the box count (its own ones, and those of another box it was confirmed to be impacted by).
 */
class OccurrenceRules
{
    public function __construct(private WorkflowJournal $journal) {}

    /**
     * @return Builder<AuditOccurrence>
     */
    public function confirmedOn(AuditBox $box): Builder
    {
        return AuditOccurrence::query()->whereHas('boxLinks', fn (Builder $links) => $links
            ->where('audit_box_id', $box->id)->whereNotNull('confirmed_at')->whereNull('excluded_at'));
    }

    /**
     * Optical anomalies neither corrected nor justified in writing: they keep the box from being submitted.
     *
     * @return list<string> References of the occurrences.
     */
    public function unaddressedOptical(AuditBox $box): array
    {
        return array_values($this->confirmedOn($box)
            ->where('nature', AuditAnomalyNature::Optical)
            ->whereIn('state', AuditOccurrenceState::toDeal())
            ->whereNull('pending_justification')
            ->orderBy('id')->pluck('reference')->all());
    }

    /**
     * Optical anomalies still waiting (justified but not corrected): only « authorise continuation », a refusal or an
     * additional control can then be decided.
     *
     * @return Collection<int, AuditOccurrence>
     */
    public function pendingOptical(AuditBox $box): Collection
    {
        return $this->confirmedOn($box)
            ->where('nature', AuditAnomalyNature::Optical)
            ->whereIn('state', AuditOccurrenceState::toDeal())
            ->orderBy('id')->lockForUpdate()->get();
    }

    /**
     * What the submission freezes about the occurrences of the box.
     *
     * @return list<array{id: int, reference: string, nature: string, state: string, type: string, description: string, pending_justification: string|null, before: list<int>, after: list<int>}>
     */
    public function snapshot(AuditBox $box): array
    {
        return $this->confirmedOn($box)->with(['type', 'photoLinks'])->orderBy('id')->get()
            ->map(fn (AuditOccurrence $occurrence) => [
                'id' => $occurrence->id,
                'reference' => $occurrence->reference,
                'nature' => $occurrence->nature->value,
                'state' => $occurrence->state->value,
                'type' => $occurrence->type->displayLabel(),
                'description' => $occurrence->description,
                'pending_justification' => $occurrence->pending_justification,
                'before' => $this->photoIds($occurrence, AuditOccurrencePhotoRole::Before),
                'after' => $this->photoIds($occurrence, AuditOccurrencePhotoRole::After),
            ])->values()->all();
    }

    /**
     * Effects of a validated inspection: corrected optical anomalies become validated (for good), detected mechanical
     * ones join the repairs of phase 2, and waiting optical ones are marked as allowed to wait when continuation was
     * authorised (still open: never « corrected »).
     *
     * @return array{validated: int, to_repair: int, authorized: int}
     */
    public function applyInspectionValidation(User $actor, AuditBox $box, bool $authorizeContinuation): array
    {
        $effects = ['validated' => 0, 'to_repair' => 0, 'authorized' => 0];
        $occurrences = $this->confirmedOn($box)
            ->whereIn('state', [...AuditOccurrenceState::toDeal(), AuditOccurrenceState::Corrected])
            ->orderBy('id')->lockForUpdate()->get();

        foreach ($occurrences as $occurrence) {
            $before = $occurrence->state;

            if ($occurrence->nature === AuditAnomalyNature::Optical && $occurrence->state === AuditOccurrenceState::Corrected) {
                $occurrence->forceFill(['state' => AuditOccurrenceState::Validated, 'validated_by' => $actor->id, 'validated_at' => now()])->save();
                $effects['validated']++;
            } elseif ($occurrence->nature === AuditAnomalyNature::Mechanical && $occurrence->state === AuditOccurrenceState::Detected) {
                $occurrence->forceFill(['state' => AuditOccurrenceState::ToRepair])->save();
                $effects['to_repair']++;
            } elseif ($occurrence->nature === AuditAnomalyNature::Optical && $authorizeContinuation) {
                $occurrence->forceFill(['pending_authorized_by' => $actor->id, 'pending_authorized_at' => now()])->save();
                $effects['authorized']++;
                $this->journal->record('occurrence_continuation_authorized', $occurrence, $actor, ['reference' => $occurrence->reference]);

                continue;
            } else {
                continue;
            }

            $this->journal->record('occurrence_state_changed', $occurrence, $actor, ['reference' => $occurrence->reference, 'old' => $before->value, 'new' => $occurrence->state->value]);
        }

        return $effects;
    }

    /**
     * @return list<int>
     */
    private function photoIds(AuditOccurrence $occurrence, AuditOccurrencePhotoRole $role): array
    {
        return $occurrence->photoLinks->where('role', $role)->pluck('audit_photo_id')->sort()->values()->all();
    }
}
