<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The agency a person works for from a given Monday on (agency changes apply from a week).
 *
 * @property int $id
 * @property int $user_id
 * @property int $agency_id
 * @property Carbon $starts_on
 */
#[Fillable(['user_id', 'agency_id', 'starts_on'])]
class UserAgencyPeriod extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['starts_on' => 'date'];
    }
}
