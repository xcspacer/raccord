<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A correction of the production time of a box by a supervisor; the original value is kept.
 *
 * @property int $id
 * @property int $audit_box_id
 * @property int $original_seconds
 * @property int $adjusted_seconds
 * @property string $justification
 * @property int $adjusted_by
 * @property Carbon $created_at
 */
#[Fillable(['audit_box_id', 'original_seconds', 'adjusted_seconds', 'justification', 'adjusted_by', 'created_at'])]
class AuditDurationAdjustment extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['original_seconds' => 'integer', 'adjusted_seconds' => 'integer', 'created_at' => 'datetime'];
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
    public function adjuster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adjusted_by');
    }
}
