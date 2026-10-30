<?php

namespace App\Policies;

use App\Enums\Feature;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Absences follow the « Absences » switch of « Gérer les accès »: the absences page also needs a daily-mode agency,
 * while adding and removing periods (from the page, the planning or the user card) only needs the switch.
 */
class AbsencePolicy
{
    public function viewAny(User $user): bool
    {
        return Gate::forUser($user)->allows('open-feature', Feature::Absences);
    }

    public function manage(User $user): bool
    {
        return Gate::forUser($user)->allows('use-feature', Feature::Absences);
    }
}
