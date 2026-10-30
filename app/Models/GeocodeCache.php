<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Result of looking up a town name (found or not), so each name is geocoded only once.
 *
 * @property int $id
 * @property string $query_key
 * @property bool $found
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string|null $place_name
 * @property Carbon $checked_at
 */
#[Fillable(['query_key', 'found', 'latitude', 'longitude', 'place_name', 'checked_at'])]
class GeocodeCache extends Model
{
    protected $table = 'geocode_cache';

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['found' => 'boolean', 'checked_at' => 'datetime'];
    }
}
