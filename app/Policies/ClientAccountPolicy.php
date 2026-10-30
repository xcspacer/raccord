<?php

namespace App\Policies;

use App\Enums\Feature;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Client portal accounts (creation, password, audits shared, branding) are managed from « Contrôle client ».
 */
class ClientAccountPolicy
{
    public function manage(User $user): bool
    {
        return Gate::forUser($user)->allows('open-feature', Feature::ClientControl);
    }
}
