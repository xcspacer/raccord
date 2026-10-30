<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One price: of a client (per intervention type, grid cell, travel fee, hour, ticket, day) or of an article of the
 * works price list (no client).
 *
 * @property int $id
 * @property int|null $billing_client_id
 * @property string $rate_key
 * @property string $amount
 * @property int|null $updated_by
 */
#[Fillable(['billing_client_id', 'rate_key', 'amount', 'updated_by'])]
class BillingRate extends Model {}
