<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Formal reopening of a closed audit by a supervisor: why, and which boxes are to be taken up again.
 *
 * @property int $id
 * @property int $audit_id
 * @property int $reopened_by
 * @property string $reason
 * @property list<int> $box_ids
 * @property Carbon $reopened_at
 */
#[Fillable(['audit_id', 'reopened_by', 'reason', 'box_ids', 'reopened_at'])]
class AuditReopening extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['box_ids' => 'array', 'reopened_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Audit, $this>
     */
    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reopener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }
}
