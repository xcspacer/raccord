<?php

namespace App\Models;

use App\Enums\AuditOccurrencePhotoRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A photo of a box linked to an occurrence in a role (before, after, other); the file itself is never duplicated.
 *
 * @property int $id
 * @property int $audit_occurrence_id
 * @property int $audit_photo_id
 * @property AuditOccurrencePhotoRole $role
 * @property int|null $linked_by
 * @property Carbon $linked_at
 */
#[Fillable(['audit_occurrence_id', 'audit_photo_id', 'role', 'linked_by', 'linked_at'])]
class AuditOccurrencePhoto extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['role' => AuditOccurrencePhotoRole::class, 'linked_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<AuditOccurrence, $this>
     */
    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(AuditOccurrence::class, 'audit_occurrence_id');
    }

    /**
     * @return BelongsTo<AuditPhoto, $this>
     */
    public function photo(): BelongsTo
    {
        return $this->belongsTo(AuditPhoto::class, 'audit_photo_id');
    }
}
