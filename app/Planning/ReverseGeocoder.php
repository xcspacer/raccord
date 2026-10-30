<?php

namespace App\Planning;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Name of the place under a GPS position (city, region, country, address), printed on photos taken with the
 * app. Positions are rounded to about 100 m before they leave the server (and in the cache key), and each place
 * is looked up once per language.
 */
class ReverseGeocoder
{
    private const string TABLE = 'reverse_geocode_cache';

    private const int PRECISION = 3;

    /**
     * @return array{city: string|null, region: string|null, country: string|null, address: string|null}|null Null when the service is unreachable.
     */
    public function lookup(float $lat, float $lng, string $locale): ?array
    {
        $lat = round($lat, self::PRECISION);
        $lng = round($lng, self::PRECISION);
        $key = number_format($lat, self::PRECISION, '.', '').','.number_format($lng, self::PRECISION, '.', '');

        $cached = DB::table(self::TABLE)->where('position_key', $key)->where('locale', $locale)
            ->where('checked_at', '>=', now()->subDays((int) config('services.geocoder.cache_days')))->first();

        if ($cached !== null) {
            return ['city' => $cached->city, 'region' => $cached->region, 'country' => $cached->country, 'address' => $cached->address];
        }

        try {
            $response = Http::timeout((int) config('services.geocoder.timeout'))->acceptJson()
                ->withUserAgent(config('app.name').' planning ('.config('app.url').')')
                ->get(rtrim((string) config('services.geocoder.reverse_url'), '/'), ['lat' => number_format($lat, self::PRECISION, '.', ''), 'lon' => number_format($lng, self::PRECISION, '.', ''), 'format' => 'jsonv2', 'zoom' => 18, 'addressdetails' => 1, 'accept-language' => $locale]);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $address = (array) $response->json('address', []);
        $place = [
            'city' => $address['city'] ?? $address['town'] ?? $address['village'] ?? $address['municipality'] ?? null,
            'region' => $address['state'] ?? $address['region'] ?? null,
            'country' => $address['country'] ?? null,
            'address' => $response->json('display_name'),
        ];

        DB::table(self::TABLE)->updateOrInsert(['position_key' => $key, 'locale' => $locale], $place + ['checked_at' => now()]);

        return $place;
    }

    /**
     * Drops the places that are too old to be used, then the oldest ones above the maximum number of entries.
     *
     * @return int The number of entries removed.
     */
    public function prune(): int
    {
        $removed = DB::table(self::TABLE)->where('checked_at', '<', now()->subDays((int) config('services.geocoder.cache_days')))->delete();
        $excess = DB::table(self::TABLE)->count() - (int) config('services.geocoder.cache_max_entries');

        if ($excess > 0) {
            $removed += DB::table(self::TABLE)->orderBy('checked_at')->orderBy('id')->limit($excess)->delete();
        }

        return $removed;
    }
}
