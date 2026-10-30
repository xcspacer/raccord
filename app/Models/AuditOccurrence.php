<?php

namespace App\Models;

use App\Enums\AuditAnomalyNature;
use App\Enums\AuditOccurrenceSource;
use App\Enums\AuditOccurrenceState;
use Database\Factories\AuditOccurrenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One distinct anomaly found on a box (OC-<cabinet>-nnnn, numbered per audit), with its life cycle, the photos that
 * prove it (before / after) and the boxes it impacts. No severity.
 *
 * @property int $id
 * @property int $audit_id
 * @property int $audit_box_id
 * @property int $sequence
 * @property string $reference
 * @property int $audit_anomaly_type_id
 * @property AuditAnomalyNature $nature
 * @property AuditOccurrenceState $state
 * @property AuditOccurrenceSource $source
 * @property string $description
 * @property string|null $location_note
 * @property string|null $action_taken
 * @property string|null $checks_done
 * @property string|null $observations
 * @property int|null $corrected_by
 * @property Carbon|null $corrected_at
 * @property string|null $pending_justification
 * @property int|null $pending_by
 * @property Carbon|null $pending_at
 * @property int|null $pending_authorized_by
 * @property Carbon|null $pending_authorized_at
 * @property int|null $validated_by
 * @property Carbon|null $validated_at
 * @property int|null $created_by
 * @property string|null $client_operation_id
 * @property Carbon $created_at
 */
#[Fillable(['audit_id', 'audit_box_id', 'sequence', 'reference', 'audit_anomaly_type_id', 'nature', 'state', 'source', 'description', 'location_note', 'action_taken', 'checks_done', 'observations', 'corrected_by', 'corrected_at', 'pending_justification', 'pending_by', 'pending_at', 'pending_authorized_by', 'pending_authorized_at', 'validated_by', 'validated_at', 'created_by', 'client_operation_id'])]
class AuditOccurrence extends Model
{
    /** @use HasFactory<AuditOccurrenceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['nature' => AuditAnomalyNature::class, 'state' => AuditOccurrenceState::class, 'source' => AuditOccurrenceSource::class, 'corrected_at' => 'datetime', 'pending_at' => 'datetime', 'pending_authorized_at' => 'datetime', 'validated_at' => 'datetime', 'sequence' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $occurrence): void {
            $before = $occurrence->getOriginal('state');

            if ($before instanceof AuditOccurrenceState && $before !== $occurrence->state && ! $before->canBecome($occurrence->state)) {
                throw new LogicException($before === AuditOccurrenceState::Validated
                    ? 'An occurrence validated by the design office is never reopened.'
                    : "An occurrence cannot go from {$before->value} to {$occurrence->state->value}.");
            }
        });
    }

    /**
     * An optical anomaly the technician could not correct now, with a written justification: only the design office
     * may let the work go on (which never means it is corrected).
     */
    public function isPending(): bool
    {
        return $this->pending_justification !== null;
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
     * @return BelongsTo<AuditAnomalyType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(AuditAnomalyType::class, 'audit_anomaly_type_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function corrector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function pendingAuthorizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pending_authorized_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    /**
     * @return HasMany<AuditOccurrenceBox, $this>
     */
    public function boxLinks(): HasMany
    {
        return $this->hasMany(AuditOccurrenceBox::class);
    }

    /**
     * @return HasMany<AuditOccurrencePhoto, $this>
     */
    public function photoLinks(): HasMany
    {
        return $this->hasMany(AuditOccurrencePhoto::class);
    }

    /**
     * @return HasMany<AuditReserveProposal, $this>
     */
    public function reserveProposals(): HasMany
    {
        return $this->hasMany(AuditReserveProposal::class);
    }

    /**
     * @return HasMany<AuditAiDraft, $this>
     */
    public function aiDrafts(): HasMany
    {
        return $this->hasMany(AuditAiDraft::class);
    }
}
