<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Validation\Rules\Password;

trait PasswordValidationRules
{
    /**
     * Get the validation rules used to validate passwords.
     *
     * @return array<int, Rule|array<mixed>|string>
     */
    protected function passwordRules(): array
    {
        return ['required', 'string', Password::min(User::MINIMUM_PASSWORD_LENGTH), 'confirmed'];
    }
}
