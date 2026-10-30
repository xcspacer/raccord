<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string|null $model
 * @property string|null $plate
 * @property string|null $plate_normalized
 */
#[Fillable(['user_id', 'model', 'plate'])]
class Vehicle extends Model
{
    use SoftDeletes;

    protected static function booted(): void
    {
        static::saving(function (Vehicle $vehicle): void {
            $vehicle->plate_normalized = $vehicle->plate === null ? null : Str::upper(preg_replace('/[^A-Za-z0-9]/', '', $vehicle->plate) ?? '');
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
