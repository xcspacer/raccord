<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Validation\ValidationException;

class DeleteUser
{
    public function __construct(private UserRules $rules) {}

    /**
     * Soft delete (restorable from the logs). Nobody deletes their own account and the last
     * administrator stays (legacy R13–R14). The person's tasks are kept.
     *
     * @throws ValidationException
     */
    public function handle(User $actor, User $user): void
    {
        if ($actor->is($user)) {
            throw ValidationException::withMessages(['user' => __('users.errors.delete_self')]);
        }

        $this->rules->ensureAnotherAdministratorRemains($user, 'user');

        $user->delete();
    }
}
