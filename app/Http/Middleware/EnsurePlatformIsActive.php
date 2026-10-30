<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformIsActive
{
    /**
     * When the platform is switched off (PLATFORM_ENABLED=false), every request gets the generic
     * "contact support" notice. Only the language switch stays available so the notice can be read.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (config('platform.enabled') || $request->routeIs('locale.update')) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => __('platform.suspended_message')], 503);
        }

        return response()->view('platform.suspended', status: 503);
    }
}
