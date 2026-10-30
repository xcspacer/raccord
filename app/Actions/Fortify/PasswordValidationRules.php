<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Validation\Rules\Password;

trait PasswordValidationRules
{
    /**
     * Get the validation rules used to validate passwords (administrators need a longer password).
     *
     * @return array<int, Rule|array<mixed>|string>
     */
    protected function passwordRules(?User $user = null): array
    {
        return ['required', 'string', Password::min($user?->minimumPasswordLength() ?? 10), 'confirmed'];
    }
}
