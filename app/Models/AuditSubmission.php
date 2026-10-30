<?php

namespace App\Models;

use App\Enums\AuditSubmissionKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * What the responsible technician sent to the design office for a box, frozen at reception: the photos, the
 * justified missing proofs and the occurrences of that moment. Never changed nor deleted on its own (it goes only with
 * its box). The operation id of the phone makes a resent submission a repeat, not a new one.
 *
 * @property int $id
 * @property int $audit_box_id
 * @property AuditSubmissionKind $kind
 * @property int $submitted_by
 * @property string $client_operation_id
 * @property array<string, mixed> $snapshot
 * @property Carbon $received_at
 */
#[Fillable(['audit_box_id', 'kind', 'submitted_by', 'client_operation_id', 'snapshot', 'received_at'])]
class AuditSubmission extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['kind' => AuditSubmissionKind::class, 'snapshot' => 'array', 'received_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A submission is immutable.'));
        static::deleting(fn () => throw new LogicException('A submission is immutable.'));
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
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * @return HasOne<AuditValidationRequest, $this>
     */
    public function validationRequest(): HasOne
    {
        return $this->hasOne(AuditValidationRequest::class);
    }

    /**
     * @return HasMany<AuditMissingProof, $this>
     */
    public function missingProofs(): HasMany
    {
        return $this->hasMany(AuditMissingProof::class);
    }
}
