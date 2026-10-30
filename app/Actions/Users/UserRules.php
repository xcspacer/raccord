<?php

namespace App\Actions\Users;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Invariants on user accounts shared by every user action.
 */
class UserRules
{
    /**
     * There must always be at least one active administrator (legacy R13).
     *
     * @throws ValidationException
     */
    public function ensureAnotherAdministratorRemains(User $user, string $field = 'role'): void
    {
        if (! $user->hasRole(Role::Administrator->value)) {
            return;
        }

        $others = User::role(Role::Administrator->value)
            ->whereKeyNot($user->getKey())
            ->where(fn ($query) => $query->whereNull('departure_date')->orWhere('departure_date', '>', now()->toDateString()))
            ->exists();

        if (! $others) {
            throw ValidationException::withMessages([$field => __('users.errors.last_administrator')]);
        }
    }

    /**
     * Non-administrator roles need an agency (legacy R11).
     */
    public function agencyRequiredFor(Role $role): bool
    {
        return $role !== Role::Administrator;
    }
}
