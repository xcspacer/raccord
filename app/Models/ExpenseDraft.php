<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Month and name of the expense report a person is drafting.
 *
 * @property int $id
 * @property int $user_id
 * @property string $month
 * @property string $name
 * @property bool $autocrop
 */
#[Fillable(['user_id', 'month', 'name', 'autocrop'])]
class ExpenseDraft extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['autocrop' => 'boolean'];
    }
}
