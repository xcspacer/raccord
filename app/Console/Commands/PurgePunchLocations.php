<?php

namespace App\Console\Commands;

use App\Models\DayPunch;
use Illuminate\Console\Command;

class PurgePunchLocations extends Command
{
    protected $signature = 'punches:purge-locations';

    protected $description = 'Erase the GPS positions of day punches older than the retention period';

    public function handle(): int
    {
        $limit = now()->subMonths((int) config('platform.location_retention_months'))->toDateString();

        $count = DayPunch::query()
            ->whereDate('date', '<', $limit)
            ->whereNull('location_purged_at')
            ->update([
                'start_latitude' => null, 'start_longitude' => null, 'start_accuracy' => null,
                'end_latitude' => null, 'end_longitude' => null, 'end_accuracy' => null,
                'location_purged_at' => now(),
            ]);

        $this->info("{$count} day punch(es) anonymised.");

        return self::SUCCESS;
    }
}
