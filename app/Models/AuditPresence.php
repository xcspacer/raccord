<?php

namespace App\Models;

use App\Enums\AuditPresenceState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Where a technician says (or is detected) to be on the audit: on the way to a box or on it.
 *
 * @property int $audit_id
 * @property int $user_id
 * @property int $audit_box_id
 * @property AuditPresenceState $state
 * @property bool $is_automatic
 * @property Carbon $since
 * @property Carbon $seen_at
 */
#[Fillable(['audit_id', 'user_id', 'audit_box_id', 'state', 'latitude', 'longitude', 'accuracy', 'is_automatic', 'since', 'seen_at'])]
class AuditPresence extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['state' => AuditPresenceState::class, 'is_automatic' => 'boolean', 'since' => 'datetime', 'seen_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
