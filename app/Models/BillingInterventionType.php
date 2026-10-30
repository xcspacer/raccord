<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One choice of a billing client's form (« VTL », « GTR4 », « Hors facturation »…); a type that is not billable
 * is never priced.
 *
 * @property int $id
 * @property int $billing_client_id
 * @property string $label
 * @property string|null $short_label
 * @property string|null $sheet_label
 * @property bool $is_billable
 * @property int $sort_order
 */
#[Fillable(['label', 'short_label', 'sheet_label', 'is_billable', 'sort_order'])]
class BillingInterventionType extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_billable' => 'boolean'];
    }

    /**
     * @return BelongsTo<BillingClient, $this>
     */
    public function billingClient(): BelongsTo
    {
        return $this->belongsTo(BillingClient::class);
    }

    /**
     * Label used in summaries and badges.
     */
    public function summaryLabel(): string
    {
        return $this->short_label ?: $this->label;
    }
}
