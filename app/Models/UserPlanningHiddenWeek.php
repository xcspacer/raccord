<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A person removed from the planning for one week only (identified by its Monday).
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon $week_starts_on
 */
#[Fillable(['user_id', 'week_starts_on'])]
class UserPlanningHiddenWeek extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['week_starts_on' => 'date'];
    }
}
