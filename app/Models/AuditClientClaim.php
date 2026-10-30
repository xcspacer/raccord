<?php

namespace App\Models;

use App\Enums\AuditClientClaimKind;
use App\Enums\AuditClientClaimState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a client raised on its portal: a dispute of a reserve (a second round goes to a supervisor) or a new
 * occurrence on a closed audit.
 *
 * @property int $id
 * @property int $audit_id
 * @property int|null $audit_box_id
 * @property int|null $audit_reserve_id
 * @property int|null $audit_occurrence_id
 * @property int|null $client_account_id
 * @property AuditClientClaimKind $kind
 * @property string $body
 * @property int $round
 * @property AuditClientClaimState $state
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_reason
 */
#[Fillable(['audit_id', 'audit_box_id', 'audit_reserve_id', 'audit_occurrence_id', 'client_account_id', 'kind', 'body', 'round', 'state', 'decided_by', 'decided_at', 'decision_reason'])]
class AuditClientClaim extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['kind' => AuditClientClaimKind::class, 'state' => AuditClientClaimState::class, 'decided_at' => 'datetime', 'round' => 'integer'];
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
     * @return BelongsTo<AuditReserve, $this>
     */
    public function reserve(): BelongsTo
    {
        return $this->belongsTo(AuditReserve::class, 'audit_reserve_id');
    }

    /**
     * @return BelongsTo<AuditOccurrence, $this>
     */
    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(AuditOccurrence::class, 'audit_occurrence_id');
    }

    /**
     * @return BelongsTo<ClientAccount, $this>
     */
    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
