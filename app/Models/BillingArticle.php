<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * An item of the works price list (« bordereau »), shared by every client with works. A coefficient (night work…)
 * is priced as a share of the other articles of the works, its price being the factor (0.5 = +50 %).
 *
 * @property int $id
 * @property string $code
 * @property string $label
 * @property string $unit
 * @property bool $is_coefficient
 * @property int $sort_order
 * @property bool $is_active
 */
#[Fillable(['code', 'label', 'unit', 'is_coefficient', 'sort_order', 'is_active'])]
class BillingArticle extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_coefficient' => 'boolean', 'is_active' => 'boolean'];
    }
}
