<?php

namespace App\Livewire\Concerns;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\ImplicitlyBoundMethod;

/**
 * Laravel's `password.confirm` for Livewire actions (AUT-07): a sensitive public action starts with
 *     if ($this->passwordConfirmationNeeded(__FUNCTION__, func_get_args())) { return; }
 * When the password was not confirmed recently (`auth.password_timeout`, same session timestamp as the
 * `password.confirm` middleware), the action is put on hold and the view's <x-password-confirmation>
 * dialog opens; the action runs, checked again on the server, once the right password is typed.
 */
trait ConfirmsPassword
{
    /** @var array{method: string, params: list<mixed>}|null */
    #[Locked]
    public ?array $pendingConfirmation = null;

    public string $confirmablePassword = '';

    /**
     * @param  array<int, mixed>  $params
     */
    protected function passwordConfirmationNeeded(string $method, array $params = []): bool
    {
        if ($this->passwordRecentlyConfirmed()) {
            return false;
        }

        $this->pendingConfirmation = ['method' => $method, 'params' => array_values($params)];
        $this->confirmablePassword = '';
        $this->resetErrorBag('confirmablePassword');

        return true;
    }

    public function confirmPassword(): void
    {
        $pending = $this->pendingConfirmation;
        $user = auth()->user();

        if ($pending === null || $user === null) {
            return;
        }

        $throttleKey = 'confirm-password:'.$user->getAuthIdentifier();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages(['confirmablePassword' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($throttleKey)])]);
        }

        if (! Hash::check($this->confirmablePassword, (string) $user->getAuthPassword())) {
            RateLimiter::hit($throttleKey);
            $this->confirmablePassword = '';

            throw ValidationException::withMessages(['confirmablePassword' => __('sessions.confirm_password.invalid')]);
        }

        RateLimiter::clear($throttleKey);
        session()->passwordConfirmed();
        $this->cancelPasswordConfirmation();

        ImplicitlyBoundMethod::call(app(), [$this, $pending['method']], $pending['params']);
    }

    public function cancelPasswordConfirmation(): void
    {
        $this->pendingConfirmation = null;
        $this->confirmablePassword = '';
        $this->resetErrorBag('confirmablePassword');
    }

    private function passwordRecentlyConfirmed(): bool
    {
        return now()->getTimestamp() - (int) session('auth.password_confirmed_at', 0) < (int) config('auth.password_timeout', 10800);
    }
}
