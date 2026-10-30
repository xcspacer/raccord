<?php

namespace App\Models;

use App\Enums\ClientRemarkStatus;
use App\Enums\ClientRemarkTarget;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A note a client leaves in the portal on a box, a control point or the whole audit; technicians must take it into
 * account and the company answers in its thread.
 *
 * @property int $id
 * @property int $audit_id
 * @property int|null $client_account_id
 * @property ClientRemarkTarget $target
 * @property int|null $audit_box_id
 * @property int|null $audit_control_point_id
 * @property string $target_label
 * @property string $body
 * @property string $author_name
 * @property string|null $author_company
 * @property ClientRemarkStatus $status
 * @property int|null $acknowledged_by
 * @property string|null $acknowledged_by_name
 * @property Carbon|null $acknowledged_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Audit $audit
 */
#[Fillable(['audit_id', 'client_account_id', 'target', 'audit_box_id', 'audit_control_point_id', 'target_label', 'body', 'author_name', 'author_company', 'status', 'acknowledged_by', 'acknowledged_by_name', 'acknowledged_at'])]
class ClientRemark extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['target' => ClientRemarkTarget::class, 'status' => ClientRemarkStatus::class, 'acknowledged_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Audit, $this>
     */
    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }

    /**
     * @return HasMany<ClientRemarkReply, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(ClientRemarkReply::class)->orderBy('created_at')->orderBy('id');
    }

    public function isClosed(): bool
    {
        return $this->status === ClientRemarkStatus::Closed;
    }

    /**
     * Still waiting for a technician to take it into account.
     */
    public function isPending(): bool
    {
        return ! $this->isClosed() && $this->acknowledged_at === null;
    }
}
