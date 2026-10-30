<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A pause declared by a technician, left out of the production time.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $audit_box_id
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property string|null $reason
 */
#[Fillable(['user_id', 'audit_box_id', 'started_at', 'ended_at', 'reason'])]
class AuditPause extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<AuditBox, $this>
     */
    public function box(): BelongsTo
    {
        return $this->belongsTo(AuditBox::class, 'audit_box_id');
    }
}
