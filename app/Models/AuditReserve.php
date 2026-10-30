<?php

namespace App\Models;

use App\Enums\AuditReserveKind;
use Database\Factories\AuditReserveFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A reserve accepted by a supervisor on a box (and usually an occurrence): kept open for a follow-up until a
 * supervisor closes it, or closed at once administratively. An accepted reserve is not a technical conformity.
 *
 * @property int $id
 * @property int $audit_box_id
 * @property int|null $audit_occurrence_id
 * @property int|null $audit_reserve_proposal_id
 * @property AuditReserveKind $kind
 * @property string $reason
 * @property int $accepted_by
 * @property Carbon $accepted_at
 * @property int|null $closed_by
 * @property Carbon|null $closed_at
 * @property string|null $closing_reason
 */
#[Fillable(['audit_box_id', 'audit_occurrence_id', 'audit_reserve_proposal_id', 'kind', 'reason', 'accepted_by', 'accepted_at', 'closed_by', 'closed_at', 'closing_reason'])]
class AuditReserve extends Model
{
    /** @use HasFactory<AuditReserveFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['kind' => AuditReserveKind::class, 'accepted_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<AuditBox, $this>
     */
    public function box(): BelongsTo
    {
        return $this->belongsTo(AuditBox::class, 'audit_box_id');
    }

    /**
     * @return BelongsTo<AuditOccurrence, $this>
     */
    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(AuditOccurrence::class, 'audit_occurrence_id');
    }

    /**
     * @return BelongsTo<AuditReserveProposal, $this>
     */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(AuditReserveProposal::class, 'audit_reserve_proposal_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function accepter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }
}
