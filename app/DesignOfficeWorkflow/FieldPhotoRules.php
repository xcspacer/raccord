<?php

namespace App\DesignOfficeWorkflow;

use App\Enums\AuditGeoMissingReason;
use App\Enums\AuditInspectionState;
use App\Enums\AuditRepairState;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\AuditPhoto;
use App\Models\StoredFile;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * What a photo must satisfy to enter an audit of the design office workflow, on top of the platform's own rules
 * (photo downscaled on the phone, real type read from the content, stored once by SHA-256, one reception per operation
 * identifier). Field photos come from the team of the box or a supervisor, never from the design office; only a
 * supervisor imports photos taken elsewhere, and they are marked « imported — no GPS ». Nothing enters a closed PM
 * (except on a box whose occurrence declared by the client is being repaired) nor a box the design office is reviewing. The location is required: without it the phone must declare that no
 * signal could be had (the photo is then kept and marked), a refused permission is not enough. The time the phone took
 * the photo is kept apart from the time the server received it; a time more than a day ahead is refused.
 */
class FieldPhotoRules
{
    /** How far ahead of the server's clock the capture time of a photo may be, in hours. */
    public const int FUTURE_TOLERANCE_HOURS = 24;

    public function __construct(private WorkflowGuard $guard, private WorkflowJournal $journal) {}

    /**
     * Checks the photo before its file is stored, in the transaction that receives it.
     *
     * @param  array{string, string}|null  $position  Usable latitude and longitude sent by the phone.
     * @return array{is_geo_missing: bool, geo_missing_reason: AuditGeoMissingReason|null}
     *
     * @throws WorkflowException
     */
    public function admit(User $actor, Audit $audit, ?AuditBox $box, bool $imported, ?array $position, mixed $declaredReason, mixed $capturedAt): array
    {
        if ($box === null) {
            $this->guard->ensureOpen($audit);
        } else {
            $this->guard->ensureOpenOrClientRepair($audit, $box);
        }

        $supervisor = WorkflowRoles::isSupervisor($actor);

        if (! $supervisor && WorkflowRoles::isReviewer($actor)) {
            throw new WorkflowException('reviewer_no_field_photos', 403);
        }

        if ($imported && ! $supervisor) {
            throw new WorkflowException('import_supervisor_only', 403);
        }

        if ($box !== null) {
            $box = AuditBox::query()->lockForUpdate()->findOrFail($box->id);
            $this->guard->ensureBoxInService($box);

            if ($box->inspection_state === AuditInspectionState::Submitted || $box->repair_state === AuditRepairState::Submitted) {
                throw new WorkflowException('box_under_review', 409, ['box' => $box->name]);
            }

            if (! $supervisor && ! $box->hasTeamMember($actor)) {
                throw new WorkflowException('not_in_team', 403, ['box' => $box->name]);
            }
        }

        if ($this->isTooFarAhead($capturedAt)) {
            throw new WorkflowException('capture_in_future', 422);
        }

        if ($imported) {
            return ['is_geo_missing' => true, 'geo_missing_reason' => AuditGeoMissingReason::Imported];
        }

        if ($position !== null) {
            return ['is_geo_missing' => false, 'geo_missing_reason' => null];
        }

        $reason = is_string($declaredReason) ? AuditGeoMissingReason::tryFrom($declaredReason) : null;

        if ($reason === null || ! in_array($reason, AuditGeoMissingReason::declarable(), true)) {
            throw new WorkflowException('location_required', 422);
        }

        return ['is_geo_missing' => true, 'geo_missing_reason' => $reason];
    }

    /**
     * The photo already received with the same content in the same place of the PM, if any: sending it again does not
     * add a second one.
     */
    public function duplicateOf(Audit $audit, ?AuditBox $box, ?int $pointId, string $folder, string $subfolder, StoredFile $stored): ?AuditPhoto
    {
        return AuditPhoto::query()
            ->where('audit_id', $audit->id)
            ->where('audit_box_id', $box?->id)
            ->where('audit_control_point_id', $pointId)
            ->where('folder', $folder)
            ->where('subfolder', $subfolder)
            ->where('stored_file_id', $stored->id)
            ->orderBy('id')
            ->first();
    }

    public function recordReceived(User $actor, AuditPhoto $photo, StoredFile $stored): void
    {
        $this->journal->record('photo_received', $photo, $actor, [
            'box_id' => $photo->audit_box_id, 'point_id' => $photo->audit_control_point_id, 'folder' => $photo->folder, 'subfolder' => $photo->subfolder,
            'sha256' => $stored->sha256, 'is_geo_missing' => $photo->is_geo_missing, 'geo_missing_reason' => $photo->geo_missing_reason?->value,
            'is_imported' => $photo->is_imported, 'captured_at' => $photo->captured_at->toIso8601String(),
        ]);
    }

    private function isTooFarAhead(mixed $capturedAt): bool
    {
        if (! is_string($capturedAt) || $capturedAt === '') {
            return false;
        }

        try {
            return CarbonImmutable::parse($capturedAt)->isAfter(now()->addHours(self::FUTURE_TOLERANCE_HOURS));
        } catch (\Throwable) {
            return false;
        }
    }
}
