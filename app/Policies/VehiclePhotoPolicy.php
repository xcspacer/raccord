<?php

namespace App\Policies;

use App\Enums\Feature;
use App\Models\User;
use App\Models\VehiclePhoto;
use App\Policies\Concerns\HidesOtherPeoplesRecords;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * A vehicle inspection photo: for its owner and the managers; anyone else gets a 404.
 */
class VehiclePhotoPolicy
{
    use HidesOtherPeoplesRecords;

    /**
     * Photograph one's own vehicle from one's space.
     */
    public function create(User $user): bool
    {
        return Gate::forUser($user)->allows('use-feature', Feature::TechnicianSpace);
    }

    public function view(User $user, VehiclePhoto $vehiclePhoto): Response
    {
        return $this->ownerOrManager($user, $vehiclePhoto->user_id);
    }
}
