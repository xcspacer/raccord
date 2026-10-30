<?php

namespace App\DesignOfficeWorkflow;

use App\Enums\AuditSynopticCorrectionState;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\AuditPhoto;
use App\Models\AuditSynopticCorrection;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Corrections of the synoptic proposed by the technicians from the field (a box missing or misnamed, a cable going
 * elsewhere…), about a box or the whole PM, with a photo of the PM when it helps. A phone may have prepared one
 * offline: its operation id makes a resent proposal answer the first one. The design office includes the open ones in
 * the consolidated synoptic it publishes (DocumentVersions) or rejects them with a reason.
 */
class SynopticCorrections
{
    public const int MIN_BODY = 5;

    public const int MAX_BODY = 2000;

    public const int MIN_REJECTION_REASON = 3;

    public function __construct(private WorkflowGuard $guard, private WorkflowJournal $journal) {}

    /**
     * @return array{correction: AuditSynopticCorrection, duplicate: bool}
     *
     * @throws WorkflowException
     */
    public function propose(User $technician, Audit $audit, ?AuditBox $box, string $operationId, string $body, ?int $photoId = null): array
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $operationId) !== 1) {
            throw new WorkflowException('operation_id_required');
        }

        $repeat = $this->repeat($technician, $audit, $operationId);

        if ($repeat !== null) {
            return ['correction' => $repeat, 'duplicate' => true];
        }

        $body = trim($body);

        if (mb_strlen($body) < self::MIN_BODY || mb_strlen($body) > self::MAX_BODY) {
            throw new WorkflowException('correction_text_required', 422, ['min' => self::MIN_BODY]);
        }

        try {
            return DB::transaction(function () use ($technician, $audit, $box, $operationId, $body, $photoId): array {
                $audit = Audit::query()->lockForUpdate()->findOrFail($audit->id);
                $this->guard->ensureOpen($audit);

                if ($box !== null && $box->audit_id !== $audit->id) {
                    throw new WorkflowException('box_not_in_audit', 404);
                }

                if ($photoId !== null && ! AuditPhoto::query()->whereKey($photoId)->where('audit_id', $audit->id)->exists()) {
                    throw new WorkflowException('photo_not_in_audit');
                }

                $correction = AuditSynopticCorrection::query()->create([
                    'audit_id' => $audit->id, 'audit_box_id' => $box?->id, 'proposed_by' => $technician->id, 'body' => $body,
                    'audit_photo_id' => $photoId, 'client_operation_id' => $operationId, 'state' => AuditSynopticCorrectionState::Open,
                ]);
                $this->journal->record('synoptic_correction_proposed', $correction, $technician, ['box' => $box?->name, 'photo_id' => $photoId, 'correlation_id' => $operationId]);

                return ['correction' => $correction, 'duplicate' => false];
            });
        } catch (UniqueConstraintViolationException) {
            return ['correction' => $this->repeat($technician, $audit, $operationId) ?? throw new WorkflowException('operation_id_reused', 409), 'duplicate' => true];
        }
    }

    /**
     * @throws WorkflowException
     */
    public function reject(User $reviewer, AuditSynopticCorrection $correction, string $reason): void
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < self::MIN_REJECTION_REASON) {
            throw new WorkflowException('reason_required');
        }

        DB::transaction(function () use ($reviewer, $correction, $reason): void {
            $correction = AuditSynopticCorrection::query()->with('audit')->lockForUpdate()->findOrFail($correction->id);
            $this->guard->ensureOpen($correction->audit);

            if (! $correction->isOpen()) {
                throw new WorkflowException('correction_already_decided', 409);
            }

            $correction->forceFill([
                'state' => AuditSynopticCorrectionState::Rejected, 'decided_by' => $reviewer->id, 'decided_at' => now(), 'decision_note' => mb_substr($reason, 0, 1000),
            ])->save();
            $this->journal->record('synoptic_correction_rejected', $correction, $reviewer, ['reason' => $reason]);
        });
    }

    /**
     * @throws WorkflowException
     */
    private function repeat(User $technician, Audit $audit, string $operationId): ?AuditSynopticCorrection
    {
        $repeat = AuditSynopticCorrection::query()->where('proposed_by', $technician->id)->where('client_operation_id', $operationId)->first();

        if ($repeat !== null && $repeat->audit_id !== $audit->id) {
            throw new WorkflowException('operation_id_reused', 409);
        }

        return $repeat;
    }
}
