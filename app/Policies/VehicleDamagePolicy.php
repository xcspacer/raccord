<?php

namespace App\Policies;

use App\Enums\Feature;
use App\Models\User;
use App\Models\VehicleDamage;
use App\Policies\Concerns\HidesOtherPeoplesRecords;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * A vehicle damage and its photo: for its owner and the managers; anyone else gets a 404.
 */
class VehicleDamagePolicy
{
    use HidesOtherPeoplesRecords;

    /**
     * Note a damage on one's own vehicle from one's space.
     */
    public function create(User $user): bool
    {
        return Gate::forUser($user)->allows('use-feature', Feature::TechnicianSpace);
    }

    public function view(User $user, VehicleDamage $vehicleDamage): Response
    {
        return $this->ownerOrManager($user, $vehicleDamage->user_id);
    }
}
