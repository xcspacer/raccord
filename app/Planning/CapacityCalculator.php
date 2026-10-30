<?php

namespace App\Planning;

use App\Enums\PlanningMode;
use App\Enums\WorkSiteStatus;
use App\Models\Agency;
use App\Models\WorkSite;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * Crew capacity of an agency. Only active work sites of detailed-planning agencies count, and every
 * calendar day of a work site counts (weekends and holidays included).
 */
class CapacityCalculator
{
    /**
     * Crews needed on each day, keyed by date.
     *
     * @return array<string, int>
     */
    public function dailyLoad(Agency $agency, ?int $exceptWorkSiteId = null): array
    {
        if ($agency->planning_mode !== PlanningMode::Detailed) {
            return [];
        }

        $load = [];

        $this->activeWorkSites($agency, $exceptWorkSiteId)->each(function (WorkSite $site) use (&$load): void {
            foreach (CarbonPeriod::create($site->starts_on, $site->ends_on) as $day) {
                $key = $day->toDateString();
                $load[$key] = ($load[$key] ?? 0) + $site->crew_count;
            }
        });

        return $load;
    }

    /**
     * Days where the crews needed exceed the capacity, keyed by date.
     *
     * @return array<string, int>
     */
    public function overbookedDays(Agency $agency): array
    {
        return array_filter($this->dailyLoad($agency), fn (int $crews) => $crews > $agency->daily_crew_capacity);
    }

    /**
     * Overbooked days grouped into runs of consecutive days.
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    public function overbookedRanges(Agency $agency): array
    {
        $days = array_keys($this->overbookedDays($agency));
        sort($days);

        $ranges = [];

        foreach ($days as $day) {
            $current = CarbonImmutable::parse($day);
            $last = array_key_last($ranges);

            if ($last !== null && $ranges[$last][1]->addDay()->eq($current)) {
                $ranges[$last][1] = $current;

                continue;
            }

            $ranges[] = [$current, $current];
        }

        return $ranges;
    }

    /**
     * Whether an active work site touches an overbooked day.
     *
     * @param  array<string, int>  $overbookedDays
     */
    public function touchesOverbookedDay(WorkSite $site, array $overbookedDays): bool
    {
        if ($site->status === WorkSiteStatus::Cancelled) {
            return false;
        }

        foreach (array_keys($overbookedDays) as $day) {
            if ($day >= $site->starts_on->toDateString() && $day <= $site->ends_on->toDateString()) {
                return true;
            }
        }

        return false;
    }

    /**
     * What saving this work site would add to the overbooking, or null when it fits.
     *
     * @return array{peak: int, capacity: int, days: int, first: CarbonImmutable, last: CarbonImmutable}|null
     */
    public function overrunFor(Agency $agency, CarbonImmutable $startsOn, CarbonImmutable $endsOn, int $crews, WorkSiteStatus $status, ?int $exceptWorkSiteId = null): ?array
    {
        if ($status === WorkSiteStatus::Cancelled || $startsOn->gt($endsOn)) {
            return null;
        }

        $load = $this->dailyLoad($agency, $exceptWorkSiteId);
        $overrun = Collection::make();

        foreach (CarbonPeriod::create($startsOn, $endsOn) as $day) {
            $needed = ($load[$day->toDateString()] ?? 0) + $crews;

            if ($needed > $agency->daily_crew_capacity) {
                $overrun->put($day->toDateString(), $needed);
            }
        }

        if ($overrun->isEmpty()) {
            return null;
        }

        return [
            'peak' => (int) $overrun->max(),
            'capacity' => $agency->daily_crew_capacity,
            'days' => $overrun->count(),
            'first' => CarbonImmutable::parse((string) $overrun->keys()->first()),
            'last' => CarbonImmutable::parse((string) $overrun->keys()->last()),
        ];
    }

    /**
     * @return Collection<int, WorkSite>
     */
    private function activeWorkSites(Agency $agency, ?int $exceptWorkSiteId): Collection
    {
        return WorkSite::query()
            ->where('agency_id', $agency->id)
            ->where('status', '!=', WorkSiteStatus::Cancelled->value)
            ->when($exceptWorkSiteId, fn ($query) => $query->whereKeyNot($exceptWorkSiteId))
            ->get(['id', 'starts_on', 'ends_on', 'crew_count']);
    }
}
