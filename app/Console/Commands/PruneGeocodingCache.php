<?php

namespace App\Console\Commands;

use App\Planning\Geocoder;
use App\Planning\ReverseGeocoder;
use Illuminate\Console\Command;

class PruneGeocodingCache extends Command
{
    protected $signature = 'geocoding:prune';

    protected $description = 'Remove expired place lookups and keep the geocoding caches under their maximum size';

    public function handle(Geocoder $towns, ReverseGeocoder $places): int
    {
        $count = $towns->prune() + $places->prune();

        $this->info("{$count} cached place(s) removed.");

        return self::SUCCESS;
    }
}
