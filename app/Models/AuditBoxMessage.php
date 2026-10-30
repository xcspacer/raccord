<?php

namespace App\Models;

use App\Enums\AuditBoxMessageKind;
use Database\Factories\AuditBoxMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Internal message on a box between the team, the design office and the supervisors: chat, early observation on a
 * synchronised photo, or request for an additional control. Blocking ones keep the box from being submitted until the
 * design office or a supervisor resolves them. Never shown to the client.
 *
 * @property int $id
 * @property int $audit_box_id
 * @property int $author_id
 * @property AuditBoxMessageKind $kind
 * @property string $body
 * @property bool $is_blocking
 * @property int|null $audit_photo_id
 * @property int|null $audit_decision_id
 * @property int|null $resolved_by
 * @property Carbon|null $resolved_at
 * @property string|null $client_operation_id
 */
#[Fillable(['audit_box_id', 'author_id', 'kind', 'body', 'is_blocking', 'audit_photo_id', 'audit_decision_id', 'resolved_by', 'resolved_at', 'client_operation_id'])]
class AuditBoxMessage extends Model
{
    /** @use HasFactory<AuditBoxMessageFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['kind' => AuditBoxMessageKind::class, 'is_blocking' => 'boolean', 'resolved_at' => 'datetime'];
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
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
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * @return BelongsTo<AuditPhoto, $this>
     */
    public function photo(): BelongsTo
    {
        return $this->belongsTo(AuditPhoto::class, 'audit_photo_id');
    }

    /**
     * @return BelongsTo<AuditDecision, $this>
     */
    public function decision(): BelongsTo
    {
        return $this->belongsTo(AuditDecision::class, 'audit_decision_id');
    }
}
