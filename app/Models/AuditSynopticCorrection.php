<?php

namespace App\Models;

use App\Enums\AuditSynopticCorrectionState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A correction of the synoptic proposed by a technician from the field (about a box or the whole cabinet, with a photo
 * when it helps). The design office includes it in the next consolidated version of the synoptic it publishes, or
 * rejects it; the technician's text is never changed. Sent once per operation identifier (offline replays).
 *
 * @property int $id
 * @property int $audit_id
 * @property int|null $audit_box_id
 * @property int $proposed_by
 * @property string $body
 * @property int|null $audit_photo_id
 * @property string $client_operation_id
 * @property AuditSynopticCorrectionState $state
 * @property int|null $audit_document_version_id
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property Carbon $created_at
 */
#[Fillable(['audit_id', 'audit_box_id', 'proposed_by', 'body', 'audit_photo_id', 'client_operation_id', 'state', 'audit_document_version_id', 'decided_by', 'decided_at', 'decision_note'])]
class AuditSynopticCorrection extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['state' => 'open'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['state' => AuditSynopticCorrectionState::class, 'decided_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Audit, $this>
     */
    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }

    /**
     * @return BelongsTo<AuditBox, $this>
     */
    public function box(): BelongsTo
    {
        return $this->belongsTo(AuditBox::class, 'audit_box_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    /**
     * @return BelongsTo<AuditPhoto, $this>
     */
    public function photo(): BelongsTo
    {
        return $this->belongsTo(AuditPhoto::class, 'audit_photo_id');
    }

    /**
     * The consolidated version of the synoptic that includes the correction.
     *
     * @return BelongsTo<AuditDocumentVersion, $this>
     */
    public function documentVersion(): BelongsTo
    {
        return $this->belongsTo(AuditDocumentVersion::class, 'audit_document_version_id');
    }

    public function isOpen(): bool
    {
        return $this->state === AuditSynopticCorrectionState::Open;
    }
}
