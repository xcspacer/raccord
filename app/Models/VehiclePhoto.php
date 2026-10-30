<?php

namespace App\Models;

use App\Enums\VehicleDamageSeverity;
use App\Enums\VehiclePhotoSlot;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A vehicle inspection photo. The current one of a slot has no archived_at; replaced or removed ones stay as history.
 *
 * @property int $id
 * @property int $user_id
 * @property VehiclePhotoSlot $slot
 * @property int $stored_file_id
 * @property string $month
 * @property Carbon $taken_at
 * @property Carbon|null $archived_at
 * @property array{damage_detected?: bool, description?: string, severity?: string, confidence?: string, error?: string}|null $comparison
 */
#[Fillable(['user_id', 'slot', 'stored_file_id', 'month', 'taken_at', 'latitude', 'longitude', 'archived_at', 'comparison'])]
class VehiclePhoto extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['slot' => VehiclePhotoSlot::class, 'taken_at' => 'datetime', 'archived_at' => 'datetime', 'comparison' => 'array'];
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'stored_file_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Severity of the damage the AI comparison found when this photo was replaced.
     */
    public function damageSeverity(): VehicleDamageSeverity
    {
        return VehicleDamageSeverity::tryFrom($this->comparison['severity'] ?? '') ?? VehicleDamageSeverity::None;
    }
}
