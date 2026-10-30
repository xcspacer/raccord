<?php

namespace App\Support;

/**
 * Distance on the ground between two GPS points.
 */
class GeoDistance
{
    /**
     * Great-circle (haversine) distance in metres.
     */
    public static function metres(float $fromLatitude, float $fromLongitude, float $toLatitude, float $toLongitude): int
    {
        $radians = fn (float $degrees) => $degrees * M_PI / 180;
        $dLat = $radians($toLatitude - $fromLatitude);
        $dLng = $radians($toLongitude - $fromLongitude);
        $h = sin($dLat / 2) ** 2 + cos($radians($fromLatitude)) * cos($radians($toLatitude)) * sin($dLng / 2) ** 2;

        return (int) round(2 * 6371000 * asin(sqrt($h)));
    }
}
