<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The design office's verdict on one repair of a repairs submission (phase 2): accepted, or refused with a reason. An
 * accepted repair is never invalidated by another refused one.
 *
 * @property int $id
 * @property int $audit_decision_id
 * @property int $audit_occurrence_id
 * @property bool $is_accepted
 * @property string|null $reason
 */
#[Fillable(['audit_decision_id', 'audit_occurrence_id', 'is_accepted', 'reason'])]
class AuditRepairItem extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_accepted' => 'boolean'];
    }

    /**
     * @return BelongsTo<AuditDecision, $this>
     */
    public function decision(): BelongsTo
    {
        return $this->belongsTo(AuditDecision::class, 'audit_decision_id');
    }

    /**
     * @return BelongsTo<AuditOccurrence, $this>
     */
    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(AuditOccurrence::class, 'audit_occurrence_id');
    }
}
