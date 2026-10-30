<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Billing settings of a person: the client their form opens on.
 *
 * @property int $user_id
 * @property int|null $default_billing_client_id
 * @property-read BillingClient|null $defaultClient
 */
#[Fillable(['user_id', 'default_billing_client_id'])]
class BillingProfile extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    /**
     * @return BelongsTo<BillingClient, $this>
     */
    public function defaultClient(): BelongsTo
    {
        return $this->belongsTo(BillingClient::class, 'default_billing_client_id');
    }
}
