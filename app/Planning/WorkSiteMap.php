<?php

namespace App\Planning;

use App\Audit\BoxName;
use App\Enums\ProjectType;
use App\Enums\WorkSiteStatus;
use App\Models\Audit;
use App\Models\WorkSite;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Data of the Accueil map: one pin per place (work sites of every agency, cancelled ones and those
 * without a town left out), plus the work sites that could not be placed. An audit work site whose town
 * is empty or unknown is placed on its cabinet, when the audit knows where the cabinet is; otherwise it is
 * listed as not located.
 *
 * @phpstan-type Item array{id: int, label: string, meta: string, status: string, running: bool, starts: string}
 * @phpstan-type Pin array{name: string, lat: float, lon: float, running: bool, confirmed: bool, items: list<Item>}
 */
class WorkSiteMap
{
    /** Lookups allowed per request; the page asks again while some are pending. */
    private const int LOOKUPS_PER_REQUEST = 3;

    public function __construct(private Geocoder $geocoder, private MapCountries $countries) {}

    /**
     * @return array{country: string, pins: list<Pin>, unlocated: list<array{city: string, count: int, id: int}>, offMap: list<array{city: string, count: int, id: int}>, total: int, placed: int, pending: int}
     */
    public function build(CarbonImmutable $today, bool $onlyCurrent): array
    {
        $sites = WorkSite::query()
            ->with(['client', 'agency'])
            ->where('status', '!=', WorkSiteStatus::Cancelled->value)
            ->where(fn ($query) => $query->where('city', '!=', '')->orWhere('project_type', ProjectType::Audit->value))
            ->when($onlyCurrent, fn ($query) => $query->whereDate('ends_on', '>=', $today))
            ->orderBy('starts_on')
            ->get();

        $budget = self::LOOKUPS_PER_REQUEST;
        $places = [];
        $unlocated = [];
        $offMap = [];
        $pending = 0;
        $located = [];
        $cabinets = $this->cabinetPositions();
        $onCabinet = collect();

        foreach ($sites->groupBy(fn (WorkSite $site) => Geocoder::key($site->city)) as $key => $group) {
            if ($key === '') {
                [$onCabinet, $group] = $this->splitOnCabinet($onCabinet, $group, $cabinets);
                array_push($unlocated, ...$group->groupBy(fn (WorkSite $site) => BoxName::cabinetKey((string) $site->cabinet_ref))->map(fn (Collection $sites) => $this->summary($sites))->values()->all());

                continue;
            }

            $entry = $this->geocoder->cached($group->first()->city);

            if ($entry === null && $budget > 0) {
                $budget--;
                $entry = $this->geocoder->lookup($group->first()->city);
            }

            if ($entry === null) {
                $pending += $group->count();

                continue;
            }

            if (! $entry->found) {
                [$onCabinet, $group] = $this->splitOnCabinet($onCabinet, $group, $cabinets);

                if ($group->isNotEmpty()) {
                    $unlocated[] = $this->summary($group);
                }

                continue;
            }

            $located[] = ['lat' => (float) $entry->latitude, 'lon' => (float) $entry->longitude, 'name' => (string) ($entry->place_name ?: $group->first()->city), 'group' => $group];
        }

        foreach ($onCabinet->groupBy(fn (WorkSite $site) => BoxName::cabinetKey((string) $site->cabinet_ref)) as $cabinetKey => $group) {
            $position = $cabinets[$cabinetKey];
            $located[] = ['lat' => $position['latitude'], 'lon' => $position['longitude'], 'name' => (string) $group->first()->cabinet_ref, 'group' => $group];
        }

        $country = $this->countries->detect(array_map(fn (array $row) => ['lat' => $row['lat'], 'lon' => $row['lon'], 'weight' => $row['group']->count()], $located));

        foreach ($located as ['lat' => $lat, 'lon' => $lon, 'name' => $name, 'group' => $group]) {
            if ($this->countries->countryOf($lat, $lon) !== $country) {
                $offMap[] = $this->summary($group);

                continue;
            }

            $cell = number_format($lat, 3, '.', '').','.number_format($lon, 3, '.', '');
            $places[$cell] ??= ['name' => $name, 'lat' => $lat, 'lon' => $lon, 'sites' => collect()];
            $places[$cell]['sites'] = $places[$cell]['sites']->merge($group);
        }

        $pins = array_values(array_map(fn (array $place) => $this->pin($place, $today), $places));

        return [
            'country' => $country,
            'pins' => $pins,
            'unlocated' => $unlocated,
            'offMap' => $offMap,
            'total' => $sites->count(),
            'placed' => (int) array_sum(array_map(fn (array $pin) => count($pin['items']), $pins)),
            'pending' => $pending,
        ];
    }

    /**
     * Position of each audited cabinet whose place is known, by cabinet key.
     *
     * @return array<string, array{latitude: float, longitude: float}>
     */
    private function cabinetPositions(): array
    {
        return Audit::query()->whereNotNull('cabinet_latitude')->whereNotNull('cabinet_longitude')->get(['cabinet_key', 'cabinet_latitude', 'cabinet_longitude'])
            ->mapWithKeys(fn (Audit $audit) => [$audit->cabinet_key => ['latitude' => (float) $audit->cabinet_latitude, 'longitude' => (float) $audit->cabinet_longitude]])
            ->all();
    }

    /**
     * Moves the audit work sites of a group whose cabinet position is known to the sites placed on their cabinet.
     *
     * @param  Collection<int, WorkSite>  $onCabinet
     * @param  Collection<int, WorkSite>  $group
     * @param  array<string, array{latitude: float, longitude: float}>  $cabinets
     * @return array{Collection<int, WorkSite>, Collection<int, WorkSite>}
     */
    private function splitOnCabinet(Collection $onCabinet, Collection $group, array $cabinets): array
    {
        [$placed, $rest] = $group->partition(fn (WorkSite $site) => $site->project_type === ProjectType::Audit && isset($cabinets[BoxName::cabinetKey((string) $site->cabinet_ref)]));

        return [$onCabinet->merge($placed), $rest->values()];
    }

    /**
     * @param  Collection<int, WorkSite>  $sites
     * @return array{city: string, count: int, id: int}
     */
    private function summary(Collection $sites): array
    {
        $first = $sites->first();
        $city = (string) $first?->city;

        if ($city === '' && $first?->project_type === ProjectType::Audit) {
            $city = trim(__('map.audit').' '.$first->cabinet_ref);
        }

        return ['city' => $city, 'count' => $sites->count(), 'id' => (int) $first?->id];
    }

    /**
     * @param  array{name: string, lat: float, lon: float, sites: Collection<int, WorkSite>}  $place
     * @return Pin
     */
    private function pin(array $place, CarbonImmutable $today): array
    {
        $items = $place['sites']->sortBy(fn (WorkSite $site) => $site->starts_on->toDateString())->values()->map(fn (WorkSite $site) => $this->item($site, $today));

        return [
            'name' => $place['name'],
            'lat' => $place['lat'],
            'lon' => $place['lon'],
            'running' => $items->contains('running', true),
            'confirmed' => $items->contains('status', WorkSiteStatus::Confirmed->value),
            'items' => $items->all(),
        ];
    }

    /**
     * @return Item
     */
    private function item(WorkSite $site, CarbonImmutable $today): array
    {
        $project = $site->project_type;
        $label = $project === ProjectType::Audit
            ? trim(__('map.audit').' '.$site->cabinet_ref)
            : trim((string) data_get($site, 'client.name').' · '.strtoupper((string) data_get($project, 'value')).' '.$site->cabinet_ref, ' ·');

        return [
            'id' => $site->id,
            'label' => $label,
            'meta' => implode(' · ', [
                $site->agency?->name,
                $site->starts_on->translatedFormat('d M').' → '.$site->ends_on->translatedFormat('d M'),
                trans_choice('planning.crews', $site->crew_count, ['count' => $site->crew_count]),
                $site->status->label(),
            ]),
            'status' => $site->status->value,
            'running' => $site->starts_on->lte($today) && $site->ends_on->gte($today),
            'starts' => $site->starts_on->toDateString(),
        ];
    }
}
