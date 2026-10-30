<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Vehicle inspection cycle: from which month on the five monthly photos are mandatory. Empty = not enforced.
 */
class VehicleSettings extends Settings
{
    /** Month (YYYY-MM) the monthly photo cycle starts, or null while it is not enforced. */
    public ?string $cycle_start_month;

    public static function group(): string
    {
        return 'vehicle';
    }
}
