<?php

namespace App\Actions\Fortify;

use App\Logs\SecurityLog;
use App\Models\User;
use App\Support\UserSessions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

/**
 * The new password chosen through the link of a « forgot password » e-mail. The person signs in again afterwards:
 * every device is signed out and the remembered devices are forgotten.
 */
class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function reset(User $user, array $input): void
    {
        if ($user->hasDeparted()) {
            throw ValidationException::withMessages(['email' => __('passwords.token')]);
        }

        Validator::make($input, [
            'password' => $this->passwordRules($user),
        ])->validate();

        $user->forceFill([
            'password' => Hash::make($input['password']),
            'legacy_password_hash' => null,
            'legacy_password_expires_at' => null,
            'must_change_password' => false,
        ])->save();

        app(UserSessions::class)->revokeAll($user, null);
        app(SecurityLog::class)->info(SecurityLog::PASSWORD_RESET_BY_EMAIL, ['user_id' => $user->id]);
    }
}
