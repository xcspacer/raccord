<?php

namespace App\Models;

use App\Enums\AuditBoxKind;
use App\Enums\AuditBoxSource;
use App\Enums\AuditBoxStatus;
use App\Enums\AuditConformity;
use App\Enums\AuditFibreType;
use App\Enums\AuditInspectionState;
use App\Enums\AuditPositionSource;
use App\Enums\AuditRepairState;
use App\Enums\AuditSupport;
use Database\Factories\AuditBoxFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A box (BPE, PBO or straight joint) of a cabinet audit.
 *
 * @property int $id
 * @property int $audit_id
 * @property string $name
 * @property AuditBoxKind $kind
 * @property string|null $type
 * @property string|null $address
 * @property AuditSupport|null $support
 * @property string|null $support_number
 * @property AuditBoxSource $source
 * @property AuditFibreType|null $fibre_type
 * @property string|null $note_route
 * @property string|null $note_before
 * @property string|null $note_tco
 * @property string|null $recheck_state
 * @property int|null $photos_count
 * @property AuditBoxStatus|null $status
 * @property string|null $reason
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string|null $confirmed_latitude
 * @property string|null $confirmed_longitude
 * @property Carbon|null $confirmed_at
 * @property list<array{id: string, label: string, qty: int}>|null $report_items
 * @property Carbon|null $report_at
 * @property string|null $report_by_name
 * @property string|null $returned_comment
 * @property Carbon|null $returned_at
 * @property Carbon|null $removed_at
 * @property Carbon|null $status_at
 * @property int|null $status_by
 * @property string|null $status_by_name
 * @property int|null $pdf_file_id
 * @property string|null $pdf_name
 * @property Carbon|null $pdf_generated_at
 * @property int|null $responsible_id
 * @property Carbon|null $responsible_since
 * @property Carbon|null $handover_requested_at
 * @property AuditInspectionState $inspection_state
 * @property AuditRepairState $repair_state
 * @property AuditConformity $conformity
 * @property bool $is_continuation_authorized
 * @property bool $are_reserves_authorized
 */
#[Fillable(['audit_id', 'name', 'kind', 'type', 'address', 'support', 'support_number', 'source', 'fibre_type', 'recheck_state', 'status', 'reason', 'status_at', 'latitude', 'longitude', 'confirmed_at', 'pdf_file_id', 'pdf_name', 'returned_comment', 'returned_by', 'returned_by_name', 'returned_at', 'removed_at', 'created_by', 'responsible_id', 'responsible_since', 'handover_requested_at', 'inspection_state', 'repair_state', 'conformity', 'is_continuation_authorized', 'are_reserves_authorized'])]
class AuditBox extends Model
{
    /** @use HasFactory<AuditBoxFactory> */
    use HasFactory;

    /**
     * Database defaults of the design office workflow, so a new box knows its states before it is read back.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['inspection_state' => 'to_do', 'repair_state' => 'none', 'conformity' => 'not_assessed', 'is_continuation_authorized' => false, 'are_reserves_authorized' => false];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => AuditBoxKind::class,
            'support' => AuditSupport::class,
            'source' => AuditBoxSource::class,
            'fibre_type' => AuditFibreType::class,
            'status' => AuditBoxStatus::class,
            'report_items' => 'array',
            'status_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'returned_at' => 'datetime',
            'removed_at' => 'datetime',
            'pdf_generated_at' => 'datetime',
            'report_at' => 'datetime',
            'responsible_since' => 'datetime',
            'handover_requested_at' => 'datetime',
            'inspection_state' => AuditInspectionState::class,
            'repair_state' => AuditRepairState::class,
            'conformity' => AuditConformity::class,
            'is_continuation_authorized' => 'boolean',
            'are_reserves_authorized' => 'boolean',
        ];
    }

    /**
     * Technician who started the box first in the design office workflow: they alone submit it.
     *
     * @return BelongsTo<User, $this>
     */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    /**
     * Other technicians who joined the box.
     *
     * @return BelongsToMany<User, $this>
     */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'audit_box_participants')->withPivot('joined_at');
    }

    /**
     * @return HasMany<AuditSubmission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(AuditSubmission::class)->orderBy('id');
    }

    /**
     * @return HasMany<AuditValidationRequest, $this>
     */
    public function validationRequests(): HasMany
    {
        return $this->hasMany(AuditValidationRequest::class)->orderBy('id');
    }

    /**
     * The most recent validation request of the box (the one the technician waits for, or the last decided).
     *
     * @return HasOne<AuditValidationRequest, $this>
     */
    public function latestValidationRequest(): HasOne
    {
        return $this->hasOne(AuditValidationRequest::class)->latestOfMany();
    }

    /**
     * @return HasMany<AuditBoxMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(AuditBoxMessage::class)->orderBy('id');
    }

    /**
     * Occurrences this box takes part in (as their origin or as an impacted box).
     *
     * @return HasMany<AuditOccurrenceBox, $this>
     */
    public function occurrenceLinks(): HasMany
    {
        return $this->hasMany(AuditOccurrenceBox::class);
    }

    /**
     * @return HasMany<AuditMissingProof, $this>
     */
    public function missingProofs(): HasMany
    {
        return $this->hasMany(AuditMissingProof::class)->orderBy('id');
    }

    /**
     * @return HasMany<AuditReserve, $this>
     */
    public function reserves(): HasMany
    {
        return $this->hasMany(AuditReserve::class)->orderBy('id');
    }

    /**
     * @return HasMany<AuditBoxPosition, $this>
     */
    public function positions(): HasMany
    {
        return $this->hasMany(AuditBoxPosition::class)->orderBy('id');
    }

    /**
     * The position of the box approved by the design office, the one « J'y vais » leads to (design office workflow).
     *
     * @return HasOne<AuditBoxPosition, $this>
     */
    public function officialPosition(): HasOne
    {
        return $this->hasOne(AuditBoxPosition::class)->ofMany(['id' => 'max'], fn ($query) => $query->where('source', AuditPositionSource::Official->value));
    }

    /**
     * Whether the user started the box or joined it.
     */
    public function hasTeamMember(User $user): bool
    {
        return $this->responsible_id === $user->id || $this->participants()->whereKey($user->id)->exists();
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function pdfFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'pdf_file_id');
    }

    /**
     * @return HasOne<AuditBoxRecheck, $this>
     */
    public function recheck(): HasOne
    {
        return $this->hasOne(AuditBoxRecheck::class);
    }

    public function isReturned(): bool
    {
        return $this->returned_at !== null;
    }

    public function isRemoved(): bool
    {
        return $this->removed_at !== null;
    }

    /**
     * Whether the box is closed: a final status that was not sent back.
     */
    public function isFinal(): bool
    {
        return $this->status?->isFinal() === true && ! $this->isReturned();
    }

    /**
     * @return BelongsTo<Audit, $this>
     */
    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }

    /**
     * @return HasMany<AuditCable, $this>
     */
    public function cables(): HasMany
    {
        return $this->hasMany(AuditCable::class)->orderBy('id');
    }

    /**
     * @return HasMany<AuditPhoto, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(AuditPhoto::class)->orderBy('captured_at')->orderBy('id');
    }

    /**
     * @return HasMany<AuditFolderValidation, $this>
     */
    public function folderValidations(): HasMany
    {
        return $this->hasMany(AuditFolderValidation::class);
    }

    /**
     * @return HasMany<AuditRemark, $this>
     */
    public function remarks(): HasMany
    {
        return $this->hasMany(AuditRemark::class)->orderBy('created_at')->orderBy('id');
    }
}
