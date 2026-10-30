<?php

namespace App\Planning;

use App\Models\Agency;
use App\Models\User;
use App\Models\UserAgencyPeriod;
use App\Models\UserPlanningHiddenWeek;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The people shown on an agency's task grid for one week: planned people who have not left, working for
 * the agency that week (agency changes apply from a Monday), minus those removed for the week.
 */
class WeekRoster
{
    /**
     * @return array{visible: Collection<int, User>, removed: Collection<int, User>}
     */
    public function for(Agency $agency, CarbonImmutable $monday): array
    {
        $people = User::query()
            ->with('vehicle')
            ->where(fn (Builder $query) => $query->where('is_plannable', true)->orWhereHas('roles', fn (Builder $query) => $query->where('name', 'technician')))
            ->where('is_hidden_from_planning', false)
            ->where(fn (Builder $query) => $query->whereNull('departure_date')->orWhereDate('departure_date', '>', $monday))
            ->orderByRaw('planning_order = 0')->orderBy('planning_order')->orderBy('name')
            ->get();

        $agencyAt = $this->agencyAt($people, $monday);
        $hidden = UserPlanningHiddenWeek::query()->whereDate('week_starts_on', $monday)->pluck('user_id')->all();

        $onAgency = $people->filter(fn (User $user) => $user->all_agencies || ($agencyAt[$user->id] ?? null) === $agency->id)->values();

        return [
            'visible' => $onAgency->reject(fn (User $user) => in_array($user->id, $hidden, true))->values(),
            'removed' => $onAgency->filter(fn (User $user) => in_array($user->id, $hidden, true))->values(),
        ];
    }

    /**
     * Agency a person works for during the week starting that Monday.
     */
    public function agencyIdFor(User $user, CarbonImmutable $monday): ?int
    {
        return $this->agencyAt(collect([$user]), $monday)[$user->id] ?? null;
    }

    /**
     * Agency of each person on that Monday: the latest change on or before it, else their own agency.
     *
     * @param  Collection<int, User>  $people
     * @return array<int, int|null>
     */
    private function agencyAt(Collection $people, CarbonImmutable $monday): array
    {
        $latest = UserAgencyPeriod::query()
            ->whereIn('user_id', $people->pluck('id'))
            ->whereDate('starts_on', '<=', $monday)
            ->orderBy('starts_on')
            ->get()
            ->groupBy('user_id')
            ->map(fn (Collection $periods) => $periods->last()?->agency_id);

        return $people->mapWithKeys(fn (User $user) => [$user->id => $latest[$user->id] ?? $user->agency_id])->all();
    }
}
