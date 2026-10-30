<?php

namespace App\Models;

use App\Enums\AuditFolder;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A mandatory photo folder the technician could not fill, with their written justification; the design office
 * accepts or refuses it when deciding the submission.
 *
 * @property int $id
 * @property int $audit_box_id
 * @property int $audit_submission_id
 * @property AuditFolder $folder
 * @property string $justification
 * @property int $justified_by
 * @property bool|null $is_accepted
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property Carbon $created_at
 */
#[Fillable(['audit_box_id', 'audit_submission_id', 'folder', 'justification', 'justified_by', 'is_accepted', 'decided_by', 'decided_at', 'created_at'])]
class AuditMissingProof extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['folder' => AuditFolder::class, 'is_accepted' => 'boolean', 'decided_at' => 'datetime', 'created_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<AuditBox, $this>
     */
    public function box(): BelongsTo
    {
        return $this->belongsTo(AuditBox::class, 'audit_box_id');
    }

    /**
     * @return BelongsTo<AuditSubmission, $this>
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(AuditSubmission::class, 'audit_submission_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'justified_by');
    }
}
