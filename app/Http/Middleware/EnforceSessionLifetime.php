<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\SessionPolicy;
use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceSessionLifetime
{
    public const string SIGNED_IN_AT = 'auth.signed_in_at';

    public const string LAST_SEEN_AT = 'auth.last_seen_at';

    /**
     * Applies the session durations of the person's role (SessionPolicy): a session idle for too long,
     * or an administrator's session older than its absolute limit, ends; a remembered device then
     * signs in again from its "remember me" cookie when the role allows it. Background refreshes
     * (Livewire polling) do not count as activity. Only sessions opened by signing in are concerned
     * (the guard's user id in the session), not a user set for one request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('web');

        if (! $request->hasSession()) {
            return $next($request);
        }

        $session = $request->session();
        $user = $guard->user();

        if (! $session->has($guard->getName())) {
            return $next($request);
        }

        if ($user instanceof User && $this->hasExpired($session, $user)) {
            $session->invalidate();
            $guard->forgetUser();
            $user = $guard->user();
        }

        if ($user instanceof User && $guard->viaRemember() && ! SessionPolicy::for($user)->remembers()) {
            $guard->logoutCurrentDevice();
            $session->invalidate();
            $user = null;
        }

        if ($user instanceof User) {
            $now = now()->getTimestamp();

            if (! $session->has(self::SIGNED_IN_AT)) {
                $session->put(self::SIGNED_IN_AT, $now);
                $session->put(self::LAST_SEEN_AT, $now);
            } elseif (! $this->isBackgroundRefresh($request)) {
                $session->put(self::LAST_SEEN_AT, $now);
            }
        }

        return $next($request);
    }

    private function hasExpired(Session $session, User $user): bool
    {
        if (! $session->has(self::SIGNED_IN_AT)) {
            return false;
        }

        return SessionPolicy::for($user)->hasExpired(
            (int) $session->get(self::LAST_SEEN_AT, $session->get(self::SIGNED_IN_AT)),
            (int) $session->get(self::SIGNED_IN_AT),
            now()->getTimestamp(),
        );
    }

    /**
     * A Livewire request made only of `wire:poll` refreshes, without any change from the person.
     */
    private function isBackgroundRefresh(Request $request): bool
    {
        $components = $request->hasHeader('X-Livewire') ? $request->input('components') : null;

        if (! is_array($components) || $components === []) {
            return false;
        }

        foreach ($components as $component) {
            $calls = is_array($component) ? ($component['calls'] ?? null) : null;

            if (! is_array($calls) || $calls === [] || ! empty($component['updates'])) {
                return false;
            }

            foreach ($calls as $call) {
                if (! is_array($call) || ($call['metadata']['type'] ?? null) !== 'poll') {
                    return false;
                }
            }
        }

        return true;
    }
}
