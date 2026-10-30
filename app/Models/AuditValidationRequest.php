<?php

namespace App\Models;

use App\Enums\AuditRequestState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A submission waiting in the design office queue: first in, first served, to the available reviewer with the fewest
 * open requests. Its deadline runs from the reception by the server; the scheduler alerts the reviewer, then marks it
 * late and alerts the supervisors. A decided request is never reopened.
 *
 * @property int $id
 * @property int $audit_submission_id
 * @property int $audit_box_id
 * @property AuditRequestState $state
 * @property int|null $assigned_to
 * @property Carbon $queued_at
 * @property Carbon $deadline_at
 * @property Carbon|null $assigned_at
 * @property Carbon|null $opened_at
 * @property Carbon|null $alerted_at
 * @property Carbon|null $late_at
 * @property Carbon|null $supervisor_alerted_at
 * @property Carbon|null $decided_at
 */
#[Fillable(['audit_submission_id', 'audit_box_id', 'state', 'assigned_to', 'queued_at', 'deadline_at', 'assigned_at', 'opened_at', 'alerted_at', 'late_at', 'supervisor_alerted_at', 'decided_at'])]
class AuditValidationRequest extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['state' => AuditRequestState::class, 'queued_at' => 'datetime', 'deadline_at' => 'datetime', 'assigned_at' => 'datetime', 'opened_at' => 'datetime', 'alerted_at' => 'datetime', 'late_at' => 'datetime', 'supervisor_alerted_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $request): void {
            if ($request->getOriginal('state') === AuditRequestState::Decided && $request->state !== AuditRequestState::Decided) {
                throw new LogicException('A decided request is never reopened.');
            }
        });
    }

    public function isDecided(): bool
    {
        return $this->state === AuditRequestState::Decided;
    }

    public function isLate(): bool
    {
        return $this->late_at !== null;
    }

    /**
     * @return BelongsTo<AuditSubmission, $this>
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(AuditSubmission::class, 'audit_submission_id');
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
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return HasOne<AuditDecision, $this>
     */
    public function decision(): HasOne
    {
        return $this->hasOne(AuditDecision::class);
    }
}
