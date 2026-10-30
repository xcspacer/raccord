<?php

namespace App\DesignOfficeWorkflow;

use App\Audit\AuditOperationException;
use App\Enums\AuditDecisionOutcome;
use App\Enums\AuditInspectionState;
use App\Enums\AuditOccurrenceState;
use App\Enums\AuditRepairState;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\AuditOccurrencePhoto;
use App\Models\AuditPhoto;
use App\Models\AuditSubmission;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the regular audit actions (the phones, the managers' « Modify » mode, moving, deleting and importing photos,
 * the recheck screens, archiving) may still change on an audit of the design office workflow. Managers correct the
 * data, but never what a decision stands on: the photos frozen in an accepted submission or proving a closed
 * anomaly are neither deleted nor moved, the photos of a box are left alone while the design office is deciding it,
 * and a box that was submitted is not removed. Once the audit is closed nothing changes any more (presence and
 * messages aside) until a formal reopening. Receiving photos has its own rules (FieldPhotoRules).
 */
class FieldDataGuard
{
    /** Operations that record nothing on the audit itself: allowed even once it is closed. */
    private const array ALWAYS_ALLOWED = ['presence', 'tech_message', 'remark_ack'];

    /** Decisions after which the photos sent are the evidence of the decision. */
    private const array ACCEPTING_OUTCOMES = [AuditDecisionOutcome::Validate, AuditDecisionOutcome::ValidateWithReserves, AuditDecisionOutcome::AuthorizeContinuation, AuditDecisionOutcome::RepairsValidated, AuditDecisionOutcome::RepairsPartial];

    public function isFrozen(Audit $audit): bool
    {
        return $audit->usesDesignOfficeWorkflow() && $audit->isClosed();
    }

    /**
     * @throws AuditOperationException
     */
    public function ensureOperationAllowed(Audit $audit, string $operation): void
    {
        if ($this->isFrozen($audit) && ! in_array($operation, self::ALWAYS_ALLOWED, true)) {
            throw new AuditOperationException('design_office_closed', 409);
        }
    }

    /**
     * @throws AuditOperationException
     */
    public function ensureNotFrozen(Audit $audit): void
    {
        if ($this->isFrozen($audit)) {
            throw new AuditOperationException('design_office_closed', 409);
        }
    }

    /**
     * Photos about to be deleted or moved.
     *
     * @param  list<int>  $photoIds
     *
     * @throws AuditOperationException
     */
    public function ensurePhotosChangeable(Audit $audit, array $photoIds): void
    {
        if (! $audit->usesDesignOfficeWorkflow() || $photoIds === []) {
            return;
        }

        $this->ensureNotFrozen($audit);

        $photos = AuditPhoto::query()->where('audit_id', $audit->id)->whereKey($photoIds)->whereNotNull('audit_box_id')->get(['id', 'audit_box_id']);

        foreach ($photos->groupBy('audit_box_id') as $boxId => $ofBox) {
            $box = AuditBox::query()->findOrFail($boxId);
            $this->ensureNotUnderReview($box);

            if (array_intersect($ofBox->modelKeys(), $this->evidence($box)) !== []) {
                throw new AuditOperationException('photo_is_evidence', 409);
            }
        }
    }

    /**
     * @throws AuditOperationException
     */
    public function ensureBoxRemovable(AuditBox $box): void
    {
        $box->loadMissing('audit');

        if ($box->audit->usesDesignOfficeWorkflow() && $box->submissions()->exists()) {
            throw new AuditOperationException('box_submitted', 409);
        }
    }

    /**
     * Ids of the photos of the box a decision stands on: sent in a submission the design office accepted, or proving
     * an anomaly now closed.
     *
     * @return list<int>
     */
    public function evidence(AuditBox $box): array
    {
        $submitted = AuditSubmission::query()->where('audit_box_id', $box->id)
            ->whereHas('validationRequest.decision.type', fn (Builder $types) => $types->whereIn('outcome', self::ACCEPTING_OUTCOMES))
            ->get(['id', 'snapshot'])
            ->flatMap(fn (AuditSubmission $submission) => array_map(fn (mixed $photo) => is_array($photo) ? (int) ($photo['id'] ?? 0) : 0, (array) ($submission->snapshot['photos'] ?? [])));
        $proving = AuditOccurrencePhoto::query()->whereHas('photo', fn (Builder $photos) => $photos->where('audit_box_id', $box->id))
            ->whereHas('occurrence', fn (Builder $occurrences) => $occurrences->whereIn('state', [AuditOccurrenceState::Validated, AuditOccurrenceState::ReserveAccepted]))
            ->pluck('audit_photo_id');

        return $submitted->merge($proving)->filter()->unique()->values()->all();
    }

    /**
     * @throws AuditOperationException
     */
    private function ensureNotUnderReview(AuditBox $box): void
    {
        if ($box->inspection_state === AuditInspectionState::Submitted || $box->repair_state === AuditRepairState::Submitted) {
            throw new AuditOperationException('box_under_review', 409);
        }
    }
}
