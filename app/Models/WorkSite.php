<?php

namespace App\Models;

use App\Enums\AuditMode;
use App\Enums\ProjectType;
use App\Enums\WorkSiteStatus;
use App\Models\Concerns\RecordsChanges;
use Database\Factories\WorkSiteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Work site (chantier) scheduled on the planning.
 *
 * @property int $id
 * @property int $agency_id
 * @property int|null $client_id
 * @property string $city
 * @property string|null $latitude
 * @property string|null $longitude
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property int $crew_count
 * @property WorkSiteStatus $status
 * @property ProjectType|null $project_type
 * @property AuditMode|null $audit_mode
 * @property string|null $site_manager
 * @property string|null $cabinet_ref
 * @property int|null $audit_id
 * @property int|null $lead_technician_id
 * @property string|null $notes
 * @property int $version
 * @property int|null $created_by
 */
#[Fillable(['agency_id', 'client_id', 'city', 'latitude', 'longitude', 'starts_on', 'ends_on', 'crew_count', 'status', 'project_type', 'audit_mode', 'site_manager', 'cabinet_ref', 'audit_id', 'lead_technician_id', 'notes', 'version', 'created_by', 'legacy_id'])]
class WorkSite extends Model
{
    /** @use HasFactory<WorkSiteFactory> */
    use HasFactory, SoftDeletes;

    use RecordsChanges;

    /**
     * Database defaults, so the journal always records complete records.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['crew_count' => 0, 'status' => 'forecast'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'crew_count' => 'integer',
            'status' => WorkSiteStatus::class,
            'project_type' => ProjectType::class,
            'audit_mode' => AuditMode::class,
            'version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function leadTechnician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_technician_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'work_site_user')->withPivot('role');
    }

    /**
     * @return HasMany<WorkSiteBox, $this>
     */
    public function boxes(): HasMany
    {
        return $this->hasMany(WorkSiteBox::class)->orderBy('position');
    }

    /**
     * @return HasMany<WorkSiteAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(WorkSiteAttachment::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public static function activityLogName(): string
    {
        return 'work_sites';
    }
}
