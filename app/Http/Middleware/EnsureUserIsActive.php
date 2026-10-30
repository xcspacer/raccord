<?php

namespace App\Http\Middleware;

use App\Enums\SessionEndReason;
use App\Models\User;
use App\Support\UserSessions;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function __construct(private UserSessions $sessions) {}

    /**
     * A person who left (departure date reached) or whose account was deleted is signed out at their
     * next request, on every device, without waiting for the session to expire.
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
        $signedInId = $session->get($guard->getName());
        $user = $guard->user();

        $disabled = $user instanceof User ? $user->hasDeparted() : $signedInId !== null;

        if (! $disabled) {
            return $next($request);
        }

        if ($user instanceof User) {
            $guard->logout();
        } else {
            $session->forget($guard->getName());
            Cookie::queue(Cookie::forget($guard->getRecallerName()));
        }

        $this->sessions->markEnded($session->getId(), SessionEndReason::UserDisabled);
        $session->invalidate();
        $session->regenerateToken();
        $this->sessions->markEnded($session->getId(), SessionEndReason::UserDisabled);
        $request->attributes->set(UserSessions::END_REASON, SessionEndReason::UserDisabled);

        return $next($request);
    }
}
