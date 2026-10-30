<?php

namespace App\Models;

use App\Enums\AuditAiDraftStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A text drafted by the AI from the photos of an occurrence, asked by the design office; always a draft until
 * approved, internal history otherwise.
 *
 * @property int $id
 * @property int $audit_occurrence_id
 * @property int|null $requested_by
 * @property list<int> $photo_ids
 * @property string $locale
 * @property string|null $model
 * @property string $text
 * @property AuditAiDraftStatus $status
 * @property int|null $edited_by
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 */
#[Fillable(['audit_occurrence_id', 'requested_by', 'photo_ids', 'locale', 'model', 'text', 'status', 'edited_by', 'approved_by', 'approved_at'])]
class AuditAiDraft extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['photo_ids' => 'array', 'status' => AuditAiDraftStatus::class, 'approved_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<AuditOccurrence, $this>
     */
    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(AuditOccurrence::class, 'audit_occurrence_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
