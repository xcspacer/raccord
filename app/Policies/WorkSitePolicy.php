<?php

namespace App\Policies;

use App\Enums\Feature;
use App\Models\User;
use App\Models\WorkSite;
use App\Support\FeatureAccess;

/**
 * Managers see, edit and delete the work sites. Creating one follows the « Nouveau chantier » switch of
 * « Gérer les accès », which can also be turned on for the design office.
 */
class WorkSitePolicy
{
    public function __construct(private FeatureAccess $access) {}

    public function viewAny(User $user): bool
    {
        return $user->isManager();
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, Feature::CreateWorkSite);
    }

    public function update(User $user, WorkSite $workSite): bool
    {
        return $user->isManager();
    }

    /**
     * Only managers change the status of a work site (D7), whatever other editing rights a role is given.
     */
    public function changeStatus(User $user, WorkSite $workSite): bool
    {
        return $user->isManager();
    }

    public function delete(User $user, WorkSite $workSite): bool
    {
        return $user->isManager();
    }
}
