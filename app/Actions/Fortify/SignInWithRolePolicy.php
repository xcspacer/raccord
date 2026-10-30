<?php

namespace App\Actions\Fortify;

use App\Http\Middleware\EnforceSessionLifetime;
use App\Models\User;
use App\Support\SessionPolicy;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Fortify;

/**
 * Fortify's sign-in step with the session rules of the person's role: the sign-in screen has no
 * "remember me" box (as in the legacy platform), so whether and for how long the device is
 * remembered comes from the role (SessionPolicy). The sign-in time starts the session clocks.
 */
class SignInWithRolePolicy extends AttemptToAuthenticate
{
    /**
     * @param  Request  $request
     * @param  callable  $next
     * @return mixed
     */
    protected function handleUsingCustomCallback($request, $next)
    {
        $user = call_user_func(Fortify::$authenticateUsingCallback, $request);

        if ($user instanceof User) {
            $policy = SessionPolicy::for($user);

            if ($policy->remembers() && $this->guard instanceof SessionGuard) {
                $this->guard->setRememberDuration($policy->rememberMinutes());
            }

            $this->guard->login($user, $policy->remembers());

            $now = now()->getTimestamp();
            $request->session()->put(EnforceSessionLifetime::SIGNED_IN_AT, $now);
            $request->session()->put(EnforceSessionLifetime::LAST_SEEN_AT, $now);

            return $next($request);
        }

        $this->fireFailedEvent($request);
        $this->throwFailedAuthenticationException($request);
    }
}
