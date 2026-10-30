<?php

namespace App\Policies;

use App\Enums\Feature;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * A person describes their own vehicle (model, plate) from their space.
 */
class VehiclePolicy
{
    public function create(User $user): bool
    {
        return Gate::forUser($user)->allows('use-feature', Feature::TechnicianSpace);
    }
}
