<?php

namespace App\Http\Middleware;

use App\ClientPortal\PortalSession;
use App\Models\Client;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureClientSession
{
    public function __construct(private PortalSession $portal) {}

    /**
     * Client portal pages: a valid client session for the client company in the address, otherwise
     * back to that portal's sign-in (saying the session expired when there was one). An account given
     * its password by the staff only reaches the password change until it has chosen its own.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Client $client */
        $client = $request->route('portalClient');
        $account = $this->portal->account($client->id);

        if ($account !== null) {
            if ($account->must_change_password && ! $request->routeIs('portal.password', 'portal.password.update')) {
                abort_if($request->expectsJson(), 403);

                return redirect()->route('portal.password', $client->portalSlug());
            }

            return $next($request);
        }

        $expired = $this->portal->hasStarted();
        $this->portal->end();

        if ($request->expectsJson()) {
            abort(401);
        }

        return redirect()->route('portal.login', $client->portalSlug())->with('portal_expired', $expired);
    }
}
