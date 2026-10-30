<?php

namespace App\Planning;

use App\Models\GeocodeCache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Places a work site's town on the map. Lookups go through the configured address service, are kept in
 * the database (found or not) and are accepted only when the answer looks like the name that was typed.
 */
class Geocoder
{
    /** A town that was not found is looked up again after this many days. */
    private const int RETRY_AFTER_DAYS = 30;

    private const float MINIMUM_SCORE = 0.5;

    public static function key(string $town): string
    {
        $plain = Str::ascii(mb_strtolower((string) preg_replace('/\(.*?\)/u', ' ', $town)));

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $plain));
    }

    /**
     * What is already known about the town, without calling the service: null when it was never looked up
     * (or the answer is old enough to look it up again).
     */
    public function cached(string $town): ?GeocodeCache
    {
        $entry = GeocodeCache::query()->where('query_key', self::key($town))->first();

        if ($entry === null || $entry->checked_at->lt(now()->subDays($entry->found ? (int) config('services.geocoder.cache_days') : self::RETRY_AFTER_DAYS))) {
            return null;
        }

        return $entry;
    }

    /**
     * Drops the answers that are too old to be used, then the oldest ones above the maximum number of entries.
     *
     * @return int The number of entries removed.
     */
    public function prune(): int
    {
        $removed = GeocodeCache::query()
            ->where(fn ($query) => $query->where('checked_at', '<', now()->subDays((int) config('services.geocoder.cache_days')))
                ->orWhere(fn ($query) => $query->where('found', false)->where('checked_at', '<', now()->subDays(self::RETRY_AFTER_DAYS))))
            ->delete();

        $excess = GeocodeCache::query()->count() - (int) config('services.geocoder.cache_max_entries');

        if ($excess > 0) {
            $removed += GeocodeCache::query()->orderBy('checked_at')->orderBy('id')->limit($excess)->delete();
        }

        return $removed;
    }

    /**
     * Looks the town up (the whole text first, then its parts separated by « et », « / », « , »…) and remembers
     * the answer. A network failure is not remembered, so it is retried later.
     */
    public function lookup(string $town): ?GeocodeCache
    {
        $key = self::key($town);

        if ($key === '') {
            return null;
        }

        if (($known = $this->cached($town)) !== null) {
            return $known;
        }

        $full = trim((string) preg_replace('/\(.*?\)/u', ' ', $town));
        $parts = array_filter(array_map('trim', preg_split('/\s+et\s+|\/+|,|;|\s-\s|\sd[ée]partement\s/iu', $full) ?: []), fn (string $part) => $part !== '' && $part !== $full);

        foreach ([$full, ...$parts] as $query) {
            try {
                $feature = $this->search($query);
            } catch (ConnectionException) {
                return null;
            }

            if ($feature !== null && $feature['score'] >= self::MINIMUM_SCORE && $this->resemble($query, $feature['name'])) {
                return $this->remember($key, true, $feature);
            }
        }

        return $this->remember($key, false, null);
    }

    /**
     * @return array{name: string, lat: float, lon: float, score: float}|null
     *
     * @throws ConnectionException
     */
    private function search(string $query): ?array
    {
        $nominatim = config('services.geocoder.driver') === 'nominatim';
        $request = Http::timeout((int) config('services.geocoder.timeout'))->acceptJson()
            ->withUserAgent(config('app.name').' planning ('.config('app.url').')');

        $response = $request->get((string) config('services.geocoder.url'), $nominatim
            ? ['q' => $query, 'format' => 'jsonv2', 'limit' => 1, 'accept-language' => app()->getLocale(), 'countrycodes' => (string) config('services.geocoder.countries')]
            : ['q' => $query, 'type' => 'municipality', 'limit' => 1]);

        if (! $response->successful()) {
            throw new ConnectionException('Geocoder unavailable');
        }

        if ($nominatim) {
            $place = $response->json('0');

            return is_array($place) && isset($place['lat'], $place['lon'])
                ? ['name' => (string) ($place['name'] ?? $place['display_name'] ?? ''), 'lat' => (float) $place['lat'], 'lon' => (float) $place['lon'], 'score' => 1.0]
                : null;
        }

        $feature = $response->json('features.0');

        if (! is_array($feature) || ! isset($feature['geometry']['coordinates'][0], $feature['geometry']['coordinates'][1])) {
            return null;
        }

        return [
            'name' => (string) ($feature['properties']['city'] ?? $feature['properties']['name'] ?? ''),
            'lat' => (float) $feature['geometry']['coordinates'][1],
            'lon' => (float) $feature['geometry']['coordinates'][0],
            'score' => (float) ($feature['properties']['score'] ?? 0),
        ];
    }

    /**
     * The answer must look like the typed town (avoids « Sans nom » or abbreviations landing anywhere).
     */
    private function resemble(string $typed, string $found): bool
    {
        $a = self::key($typed);
        $b = self::key($found);

        if ($a === '' || $b === '') {
            return false;
        }

        if (str_contains($a, $b) || str_contains($b, $a)) {
            return true;
        }

        $foundWords = explode(' ', $b);

        foreach (explode(' ', $a) as $word) {
            if (mb_strlen($word) < 4) {
                continue;
            }

            foreach ($foundWords as $other) {
                if (str_starts_with($other, $word) || str_starts_with($word, $other)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array{name: string, lat: float, lon: float, score: float}|null  $feature
     */
    private function remember(string $key, bool $found, ?array $feature): GeocodeCache
    {
        return GeocodeCache::query()->updateOrCreate(['query_key' => $key], [
            'found' => $found,
            'latitude' => $feature['lat'] ?? null,
            'longitude' => $feature['lon'] ?? null,
            'place_name' => $feature['name'] ?? null,
            'checked_at' => now(),
        ]);
    }
}
