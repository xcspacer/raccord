<?php

namespace App\Console\Commands;

use App\Models\VehiclePhoto;
use Illuminate\Console\Command;

/**
 * Vehicle photos follow the retention of day punch positions (RGPD-04): the GPS position is erased, the photo stays.
 */
class PurgeVehiclePhotoLocations extends Command
{
    protected $signature = 'vehicle-photos:purge-locations';

    protected $description = 'Erase the GPS positions of vehicle photos older than the retention period';

    public function handle(): int
    {
        $count = VehiclePhoto::query()
            ->where('taken_at', '<', now()->subMonths((int) config('platform.location_retention_months')))
            ->where(fn ($query) => $query->whereNotNull('latitude')->orWhereNotNull('longitude'))
            ->update(['latitude' => null, 'longitude' => null]);

        $this->info("{$count} vehicle photo position(s) erased.");

        return self::SUCCESS;
    }
}
