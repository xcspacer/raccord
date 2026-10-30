<?php

namespace App\Models;

use App\Enums\AuditPlanKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A plan file attached to an audit: the drawer plan, or the plan of one box (matched to a box by its file name).
 *
 * @property int $id
 * @property int $audit_id
 * @property AuditPlanKind $kind
 * @property string $name
 * @property int $stored_file_id
 */
#[Fillable(['audit_id', 'kind', 'name', 'stored_file_id', 'uploaded_by'])]
class AuditPlan extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['kind' => AuditPlanKind::class];
    }

    /**
     * @return BelongsTo<Audit, $this>
     */
    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'stored_file_id');
    }
}
