<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A network operator an hourly breakdown is done for.
 *
 * @property int $id
 * @property string $name
 */
#[Fillable(['name'])]
class BillingOperator extends Model {}
