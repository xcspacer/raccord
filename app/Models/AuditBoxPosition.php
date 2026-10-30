<?php

namespace App\Models;

use App\Enums\AuditPositionSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A position of a box, never overwritten: read from the map file, taken on site, proposed as a correction, or the
 * official one approved by the design office (the one « J'y vais » leads to).
 *
 * @property int $id
 * @property int $audit_box_id
 * @property AuditPositionSource $source
 * @property string $latitude
 * @property string $longitude
 * @property int|null $accuracy
 * @property int|null $audit_document_version_id
 * @property int|null $recorded_by
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property int|null $rejected_by
 * @property Carbon|null $rejected_at
 * @property string|null $rejection_reason
 * @property Carbon $created_at
 */
#[Fillable(['audit_box_id', 'source', 'latitude', 'longitude', 'accuracy', 'audit_document_version_id', 'recorded_by', 'approved_by', 'approved_at', 'rejected_by', 'rejected_at', 'rejection_reason'])]
class AuditBoxPosition extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['source' => AuditPositionSource::class, 'approved_at' => 'datetime', 'rejected_at' => 'datetime', 'accuracy' => 'integer'];
    }

    /**
     * A proposed correction the design office has not approved nor rejected yet.
     */
    public function isPending(): bool
    {
        return $this->source === AuditPositionSource::Proposed && $this->approved_at === null && $this->rejected_at === null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<AuditBox, $this>
     */
    public function box(): BelongsTo
    {
        return $this->belongsTo(AuditBox::class, 'audit_box_id');
    }

    /**
     * @return BelongsTo<AuditDocumentVersion, $this>
     */
    public function documentVersion(): BelongsTo
    {
        return $this->belongsTo(AuditDocumentVersion::class, 'audit_document_version_id');
    }
}
