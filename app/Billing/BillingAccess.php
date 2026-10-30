<?php

namespace App\Billing;

use App\Enums\Feature;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Who uses « Pointage / Facturation » and how: administrators manage everything, supervisors follow the team
 * without amounts, technicians declare their own entries and expenses.
 */
class BillingAccess
{
    /** @var Collection<int, User>|null */
    private ?Collection $people = null;

    public function canUse(User $user): bool
    {
        return $user->can('use-feature', Feature::Billing);
    }

    public function isAdministrator(User $user): bool
    {
        return $user->hasRole(Role::Administrator->value);
    }

    public function isSupervisor(User $user): bool
    {
        return $user->hasRole(Role::Supervisor->value);
    }

    /**
     * Everyone who may use billing (technicians, supervisors, administrators), the person excluded, by name.
     *
     * @return Collection<int, User>
     */
    public function partnerCandidates(User $user): Collection
    {
        $this->people ??= User::query()
            ->whereNull('anonymised_at')
            ->role([Role::Administrator->value, Role::Supervisor->value, Role::Technician->value])
            ->orderBy('name')
            ->get(['id', 'name', 'username'])
            ->filter(fn (User $candidate) => $this->canUse($candidate))
            ->values();

        return $this->people->reject(fn (User $candidate) => $candidate->id === $user->id)->values();
    }
}
