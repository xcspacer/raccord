<?php

namespace App\Planning;

/**
 * The country maps available for the Accueil map (resources/maps/<code>.geojson: mainland regions only;
 * far islands and overseas territories are deliberately left out and listed under the map instead).
 * The country shown is the one that holds most of the places found, so it follows the data.
 */
class MapCountries
{
    public const string DEFAULT = 'fr';

    /** @var list<string> */
    public const array CODES = ['fr', 'pt', 'es', 'ua'];

    /** A place slightly outside the simplified outline (coast) still belongs to the country within this distance, in degrees. */
    private const float COAST_TOLERANCE = 0.25;

    /** @var array<string, list<list<array{0: float, 1: float}>>> */
    private array $outlines = [];

    /**
     * Country of a place, or null when it is on none of the maps.
     */
    public function countryOf(float $lat, float $lon): ?string
    {
        foreach (self::CODES as $code) {
            foreach ($this->outlines($code) as $ring) {
                if ($this->inside($ring, $lon, $lat)) {
                    return $code;
                }
            }
        }

        $nearest = null;
        $best = self::COAST_TOLERANCE;

        foreach (self::CODES as $code) {
            foreach ($this->outlines($code) as $ring) {
                foreach ($ring as [$x, $y]) {
                    $distance = hypot($x - $lon, $y - $lat);

                    if ($distance < $best) {
                        $best = $distance;
                        $nearest = $code;
                    }
                }
            }
        }

        return $nearest;
    }

    /**
     * The map to show: the country with the most work sites among the given places.
     *
     * @param  list<array{lat: float, lon: float, weight: int}>  $places
     */
    public function detect(array $places): string
    {
        $totals = [];

        foreach ($places as $place) {
            $code = $this->countryOf($place['lat'], $place['lon']);

            if ($code !== null) {
                $totals[$code] = ($totals[$code] ?? 0) + $place['weight'];
            }
        }

        if ($totals === []) {
            return self::DEFAULT;
        }

        arsort($totals);

        return (string) array_key_first($totals);
    }

    /**
     * Outer rings of every polygon of the country map.
     *
     * @return list<list<array{0: float, 1: float}>>
     */
    private function outlines(string $code): array
    {
        if (isset($this->outlines[$code])) {
            return $this->outlines[$code];
        }

        $rings = [];
        $data = json_decode((string) file_get_contents(resource_path('maps/'.$code.'.geojson')), true);

        foreach ($data['features'] ?? [] as $feature) {
            $geometry = $feature['geometry'];
            $polygons = $geometry['type'] === 'Polygon' ? [$geometry['coordinates']] : $geometry['coordinates'];

            foreach ($polygons as $polygon) {
                $rings[] = $polygon[0];
            }
        }

        return $this->outlines[$code] = $rings;
    }

    /**
     * @param  list<array{0: float, 1: float}>  $ring
     */
    private function inside(array $ring, float $x, float $y): bool
    {
        $inside = false;

        for ($a = 0, $b = count($ring) - 1; $a < count($ring); $b = $a++) {
            [$xa, $ya] = $ring[$a];
            [$xb, $yb] = $ring[$b];

            if (($ya > $y) !== ($yb > $y) && $x < ($xb - $xa) * ($y - $ya) / ($yb - $ya) + $xa) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }
}
