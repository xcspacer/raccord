<?php

namespace App\Models;

use App\Enums\AuditReserveProposalState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A reserve proposed on an occurrence by the design office or a supervisor; only a supervisor accepts it. One open
 * proposal per occurrence at most (guard column `open_occurrence_id`).
 *
 * @property int $id
 * @property int $audit_occurrence_id
 * @property int $proposed_by
 * @property string $reason
 * @property AuditReserveProposalState $state
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_reason Why a supervisor refused it.
 */
#[Fillable(['audit_occurrence_id', 'proposed_by', 'reason', 'state', 'decided_by', 'decided_at', 'decision_reason'])]
class AuditReserveProposal extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['state' => AuditReserveProposalState::class, 'decided_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $proposal): void {
            $proposal->setAttribute('open_occurrence_id', $proposal->state === AuditReserveProposalState::Open ? $proposal->audit_occurrence_id : null);
        });
    }

    /**
     * @return BelongsTo<AuditOccurrence, $this>
     */
    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(AuditOccurrence::class, 'audit_occurrence_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
