<?php

namespace App\Models;

use App\Enums\AuditMode;
use App\Enums\AuditWorkflow;
use App\Enums\AuditWorkflowPhase;
use Database\Factories\AuditFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * The audit of one cabinet (PM): its boxes and control points, photographed on site. Every change made by a
 * person raises the revision, which clients poll to know whether to reload.
 *
 * @property int $id
 * @property string $cabinet_key
 * @property string|null $sro
 * @property AuditMode|null $mode
 * @property string|null $folder
 * @property int $revision
 * @property string|null $cabinet_latitude
 * @property string|null $cabinet_longitude
 * @property string|null $cabinet_name
 * @property int|null $synoptic_file_id
 * @property string|null $synoptic_name
 * @property int|null $kmz_file_id
 * @property string|null $kmz_name
 * @property int|null $finished_by
 * @property string|null $finished_by_name
 * @property Carbon|null $finished_at
 * @property int|null $validated_by
 * @property string|null $validated_by_name
 * @property Carbon|null $validated_at
 * @property string|null $original_cabinet_key
 * @property AuditWorkflow $workflow
 * @property Carbon|null $workflow_locked_at
 * @property AuditWorkflowPhase $phase
 * @property int|null $closed_by
 * @property Carbon|null $closed_at
 * @property Carbon|null $deleted_at
 * @property string|null $deleted_by_name
 */
#[Fillable(['cabinet_key', 'sro', 'mode', 'folder', 'revision', 'synoptic_file_id', 'synoptic_name', 'kmz_file_id', 'kmz_name', 'finished_by', 'finished_by_name', 'finished_at', 'validated_by', 'validated_by_name', 'validated_at', 'workflow', 'workflow_locked_at', 'phase', 'closed_by', 'closed_at'])]
class Audit extends Model
{
    /** @use HasFactory<AuditFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Database defaults, so a new audit knows its workflow before it is read back.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['workflow' => 'standard', 'phase' => 1];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => AuditMode::class, 'revision' => 'integer', 'finished_at' => 'datetime', 'validated_at' => 'datetime',
            'workflow' => AuditWorkflow::class, 'workflow_locked_at' => 'datetime', 'phase' => AuditWorkflowPhase::class, 'closed_at' => 'datetime',
        ];
    }

    /**
     * Whether the boxes of the audit are validated one by one by the design office (spec 13).
     */
    public function usesDesignOfficeWorkflow(): bool
    {
        return $this->workflow === AuditWorkflow::DesignOffice;
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * @return HasMany<AuditOccurrence, $this>
     */
    public function occurrences(): HasMany
    {
        return $this->hasMany(AuditOccurrence::class);
    }

    /**
     * @return HasMany<AuditIntervention, $this>
     */
    public function interventions(): HasMany
    {
        return $this->hasMany(AuditIntervention::class);
    }

    /**
     * @return HasMany<AuditDocumentVersion, $this>
     */
    public function documentVersions(): HasMany
    {
        return $this->hasMany(AuditDocumentVersion::class);
    }

    /**
     * @return HasMany<AuditSynopticCorrection, $this>
     */
    public function synopticCorrections(): HasMany
    {
        return $this->hasMany(AuditSynopticCorrection::class);
    }

    /**
     * @return HasMany<AuditDossierVersion, $this>
     */
    public function dossierVersions(): HasMany
    {
        return $this->hasMany(AuditDossierVersion::class);
    }

    /**
     * @return HasMany<AuditClientClaim, $this>
     */
    public function clientClaims(): HasMany
    {
        return $this->hasMany(AuditClientClaim::class);
    }

    /**
     * @return HasMany<AuditReopening, $this>
     */
    public function reopenings(): HasMany
    {
        return $this->hasMany(AuditReopening::class);
    }

    /**
     * Mode the audit is run in: the full one unless the optical one was chosen.
     */
    public function effectiveMode(): AuditMode
    {
        return $this->mode ?? AuditMode::Full;
    }

    public function isValidated(): bool
    {
        return $this->validated_at !== null;
    }

    public function isFinished(): bool
    {
        return $this->finished_at !== null;
    }

    /**
     * @return HasMany<AuditPlan, $this>
     */
    public function plans(): HasMany
    {
        return $this->hasMany(AuditPlan::class)->orderBy('name');
    }

    /**
     * @return HasMany<AuditBox, $this>
     */
    public function boxes(): HasMany
    {
        return $this->hasMany(AuditBox::class);
    }

    /**
     * @return HasMany<AuditControlPoint, $this>
     */
    public function controlPoints(): HasMany
    {
        return $this->hasMany(AuditControlPoint::class)->orderBy('id');
    }

    /**
     * @return HasMany<ClientRemark, $this>
     */
    public function clientRemarks(): HasMany
    {
        return $this->hasMany(ClientRemark::class);
    }

    /**
     * @return HasMany<AuditPresence, $this>
     */
    public function presences(): HasMany
    {
        return $this->hasMany(AuditPresence::class);
    }

    /**
     * @return HasMany<AuditPhoto, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(AuditPhoto::class);
    }
}
