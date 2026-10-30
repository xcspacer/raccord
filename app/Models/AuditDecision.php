<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * The single decision of the design office on a validation request (unique per request: the first one recorded
 * wins). Time is the server's. Never changed nor deleted on its own.
 *
 * @property int $id
 * @property int $audit_validation_request_id
 * @property int $audit_decision_type_id
 * @property int $decided_by
 * @property string|null $reason
 * @property list<string>|null $targets
 * @property Carbon $decided_at
 */
#[Fillable(['audit_validation_request_id', 'audit_decision_type_id', 'decided_by', 'reason', 'targets', 'decided_at'])]
class AuditDecision extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['targets' => 'array', 'decided_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A decision is immutable.'));
        static::deleting(fn () => throw new LogicException('A decision is immutable.'));
    }

    /**
     * @return BelongsTo<AuditValidationRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(AuditValidationRequest::class, 'audit_validation_request_id');
    }

    /**
     * @return BelongsTo<AuditDecisionType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(AuditDecisionType::class, 'audit_decision_type_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return HasMany<AuditRepairItem, $this>
     */
    public function repairItems(): HasMany
    {
        return $this->hasMany(AuditRepairItem::class);
    }
}
