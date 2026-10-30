<?php

namespace App\Support;

use App\Enums\PlanningMode;
use App\Enums\Role;
use App\Models\Agency;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Collection;

/**
 * The agency a user is working on: fixed for users attached to one agency; chosen with the agency
 * switcher (kept in the session) by administrators and users working for every agency.
 */
class EffectiveAgency
{
    public const string SESSION_KEY = 'agency_id';

    public function __construct(private Session $session) {}

    public function canSwitch(User $user): bool
    {
        return $user->hasRole(Role::Administrator->value) || $user->all_agencies || $user->agency_id === null;
    }

    public function for(User $user): ?Agency
    {
        if (! $this->canSwitch($user)) {
            return $user->agency;
        }

        $agencies = $this->agencies();

        return $agencies->firstWhere('id', $this->session->get(self::SESSION_KEY)) ?? $agencies->first();
    }

    public function switchTo(User $user, Agency $agency): void
    {
        abort_unless($this->canSwitch($user), 403);

        $this->session->put(self::SESSION_KEY, $agency->id);
    }

    public function isDailyMode(User $user): bool
    {
        return $this->for($user)?->planning_mode === PlanningMode::Daily;
    }

    /**
     * @return Collection<int, Agency>
     */
    public function agencies(): Collection
    {
        return once(fn () => Agency::query()->orderBy('sort_order')->orderBy('id')->get());
    }
}
