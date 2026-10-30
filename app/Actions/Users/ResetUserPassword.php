<?php

namespace App\Actions\Users;

use App\Models\User;
use App\Support\UserSessions;

class ResetUserPassword
{
    public function __construct(private UserSessions $sessions) {}

    /**
     * Administrator sets a temporary password: the person must change it at the next sign-in, and
     * their other sessions are signed out.
     */
    public function handle(User $user, string $password): void
    {
        $user->forceFill([
            'password' => $password,
            'legacy_password_hash' => null,
            'legacy_password_expires_at' => null,
            'must_change_password' => true,
        ])->save();

        $this->sessions->revokeAll($user, null);
    }
}
