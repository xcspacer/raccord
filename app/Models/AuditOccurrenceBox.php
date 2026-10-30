<?php

namespace App\Models;

use App\Enums\AuditConformity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A box concerned by an occurrence: its origin box, or a box the field proposed (or the design office added) as
 * impacted. Only confirmed boxes count; an excluded one keeps the reason. The result says whether that box conforms.
 *
 * @property int $id
 * @property int $audit_occurrence_id
 * @property int $audit_box_id
 * @property bool $is_origin
 * @property bool $is_suggested
 * @property string|null $justification
 * @property int|null $added_by
 * @property Carbon $added_at
 * @property int|null $confirmed_by
 * @property Carbon|null $confirmed_at
 * @property int|null $excluded_by
 * @property Carbon|null $excluded_at
 * @property string|null $exclusion_reason
 * @property AuditConformity $conformity
 * @property string|null $note
 */
#[Fillable(['audit_occurrence_id', 'audit_box_id', 'is_origin', 'is_suggested', 'justification', 'added_by', 'added_at', 'confirmed_by', 'confirmed_at', 'excluded_by', 'excluded_at', 'exclusion_reason', 'conformity', 'note'])]
class AuditOccurrenceBox extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_origin' => 'boolean', 'is_suggested' => 'boolean', 'added_at' => 'datetime', 'confirmed_at' => 'datetime', 'excluded_at' => 'datetime', 'conformity' => AuditConformity::class];
    }

    /**
     * @return BelongsTo<AuditOccurrence, $this>
     */
    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(AuditOccurrence::class, 'audit_occurrence_id');
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
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function excluder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'excluded_by');
    }
}
