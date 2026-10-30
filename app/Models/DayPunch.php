<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person's day punch (clock-in and clock-out), unique per person and company day.
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon $date
 * @property Carbon $start_at
 * @property string|null $start_latitude
 * @property string|null $start_longitude
 * @property int|null $start_accuracy
 * @property bool $start_geo_missing
 * @property string|null $start_geo_reason
 * @property bool $start_offline
 * @property Carbon|null $end_at
 * @property string|null $end_latitude
 * @property string|null $end_longitude
 * @property int|null $end_accuracy
 * @property bool $end_geo_missing
 * @property string|null $end_geo_reason
 * @property bool $end_offline
 * @property bool $end_declared
 * @property Carbon|null $location_purged_at
 */
#[Fillable(['user_id', 'date', 'start_at', 'start_latitude', 'start_longitude', 'start_accuracy', 'start_geo_missing', 'start_geo_reason', 'start_offline', 'start_received_at', 'end_at', 'end_latitude', 'end_longitude', 'end_accuracy', 'end_geo_missing', 'end_geo_reason', 'end_offline', 'end_declared', 'end_received_at'])]
class DayPunch extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'start_received_at' => 'datetime',
            'end_received_at' => 'datetime',
            'location_purged_at' => 'datetime',
            'start_geo_missing' => 'boolean',
            'end_geo_missing' => 'boolean',
            'start_offline' => 'boolean',
            'end_offline' => 'boolean',
            'end_declared' => 'boolean',
            'start_accuracy' => 'integer',
            'end_accuracy' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOpen(): bool
    {
        return $this->end_at === null;
    }

    /**
     * Whether the start position is less accurate than the warning threshold (D9: shown to managers, never refused).
     */
    public function hasImpreciseStart(): bool
    {
        return $this->start_latitude !== null && self::isImprecise($this->start_accuracy);
    }

    public function hasImpreciseEnd(): bool
    {
        return $this->end_latitude !== null && self::isImprecise($this->end_accuracy);
    }

    private static function isImprecise(?int $accuracy): bool
    {
        return $accuracy !== null && $accuracy > (int) config('platform.location_accuracy_warning_metres');
    }

    /**
     * Hour shown for the start, in the company calendar.
     */
    public function startLocal(): string
    {
        return $this->start_at->copy()->timezone(config('platform.timezone'))->format('H:i');
    }

    public function endLocal(): ?string
    {
        return $this->end_at?->copy()->timezone(config('platform.timezone'))->format('H:i');
    }
}
