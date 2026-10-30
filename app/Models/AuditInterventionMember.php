<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A technician of the team of a repair intervention.
 *
 * @property int $id
 * @property int $audit_intervention_id
 * @property int $user_id
 */
#[Fillable(['audit_intervention_id', 'user_id'])]
class AuditInterventionMember extends Model
{
    public $timestamps = false;

    /**
     * @return BelongsTo<AuditIntervention, $this>
     */
    public function intervention(): BelongsTo
    {
        return $this->belongsTo(AuditIntervention::class, 'audit_intervention_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
