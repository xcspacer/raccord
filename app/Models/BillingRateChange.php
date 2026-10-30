<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * History of a price change, kept for audit.
 *
 * @property int $id
 * @property int|null $billing_client_id
 * @property string $rate_key
 * @property string|null $old_amount
 * @property string $new_amount
 * @property int|null $changed_by
 */
#[Fillable(['billing_client_id', 'rate_key', 'old_amount', 'new_amount', 'changed_by'])]
class BillingRateChange extends Model
{
    public const UPDATED_AT = null;
}
