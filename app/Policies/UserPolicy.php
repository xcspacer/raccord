<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * People are created, edited, deleted and exported by administrators only (AUTZ-07). Each person and the
 * administrators see and end that person's sessions; for anyone else they do not exist (404).
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdministrator($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdministrator($user);
    }

    /**
     * Everything on the person's card: role, agency, company, contact, vehicle, departure, password, planning flags.
     */
    public function update(User $user, ?User $person = null): bool
    {
        return $this->isAdministrator($user);
    }

    public function delete(User $user, User $person): bool
    {
        return $this->isAdministrator($user);
    }

    public function exportPersonalData(User $user, ?User $person = null): bool
    {
        return $this->isAdministrator($user);
    }

    /**
     * One's own profile only: contact, password, language and personal documents (s01 3.10).
     */
    public function updateProfile(User $user, User $person): bool
    {
        return $user->is($person);
    }

    public function manageSessions(User $user, User $person): Response
    {
        return $user->is($person) || $this->isAdministrator($user) ? Response::allow() : Response::denyAsNotFound();
    }

    private function isAdministrator(User $user): bool
    {
        return $user->hasRole(Role::Administrator->value);
    }
}
