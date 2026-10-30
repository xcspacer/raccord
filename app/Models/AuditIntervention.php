<?php

namespace App\Models;

use App\Enums\AuditInterventionStatus;
use Database\Factories\AuditInterventionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A repair visit to a box planned by a supervisor (phase 2): date, responsible technician and members. One open
 * intervention per box at most (guard column `open_box_id`).
 *
 * @property int $id
 * @property int $audit_id
 * @property int $audit_box_id
 * @property Carbon $planned_on
 * @property int $responsible_id
 * @property string|null $note
 * @property AuditInterventionStatus $status
 * @property int|null $created_by
 * @property Carbon|null $submitted_at
 * @property Carbon|null $closed_at
 * @property int|null $cancelled_by
 * @property Carbon|null $cancelled_at
 * @property string|null $cancellation_reason
 */
#[Fillable(['audit_id', 'audit_box_id', 'planned_on', 'responsible_id', 'note', 'status', 'created_by', 'submitted_at', 'closed_at', 'cancelled_by', 'cancelled_at', 'cancellation_reason'])]
class AuditIntervention extends Model
{
    /** @use HasFactory<AuditInterventionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['planned_on' => 'date', 'status' => AuditInterventionStatus::class, 'submitted_at' => 'datetime', 'closed_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $intervention): void {
            $intervention->setAttribute('open_box_id', $intervention->status->isOpen() ? $intervention->audit_box_id : null);
        });
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
     * @return BelongsTo<User, $this>
     */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'audit_intervention_members');
    }
}
