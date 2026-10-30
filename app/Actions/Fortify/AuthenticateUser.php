<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthenticateUser
{
    public function __invoke(Request $request): ?User
    {
        $username = Str::lower(trim((string) $request->input('username')));
        $password = (string) $request->input('password');

        $user = User::query()->where('username', $username)->first();

        if ($user === null) {
            // Same hashing cost as a real check, so response times do not reveal which usernames exist.
            Hash::make($password);

            return null;
        }

        if ($user->hasDeparted() || ! $this->passwordMatches($user, $password)) {
            return null;
        }

        return $user;
    }

    private function passwordMatches(User $user, string $password): bool
    {
        if ($user->password !== null) {
            if (! Hash::check($password, $user->password)) {
                return false;
            }

            if (Hash::needsRehash($user->password)) {
                $user->forceFill(['password' => $password])->save();
            }

            return true;
        }

        return $this->upgradeLegacyPassword($user, $password);
    }

    /**
     * Legacy accounts store "salt:sha256(salt + ':' + password)". On the first successful login the
     * password is re-hashed with the framework hasher and a password change is required, because the
     * legacy hashes were exposed.
     */
    private function upgradeLegacyPassword(User $user, string $password): bool
    {
        if ($user->legacy_password_hash === null || ! str_contains($user->legacy_password_hash, ':')) {
            return false;
        }

        if ($user->legacy_password_expires_at?->isPast()) {
            return false;
        }

        [$salt, $expectedHash] = explode(':', $user->legacy_password_hash, 2);

        if (! hash_equals($expectedHash, hash('sha256', $salt.':'.$password))) {
            return false;
        }

        $user->forceFill([
            'password' => $password,
            'legacy_password_hash' => null,
            'legacy_password_expires_at' => null,
            'must_change_password' => true,
        ])->save();

        return true;
    }
}
