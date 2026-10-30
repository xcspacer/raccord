<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCurrentSyncProtocol
{
    /**
     * Version of what the phones send to the field sync endpoints; it goes up with every change of their format, together
     * with PROTOCOL_VERSION in resources/js/audit/sync.js.
     */
    public const int VERSION = 1;

    public const string HEADER = 'X-Sync-Version';

    /**
     * Usage: ->middleware('sync.protocol'). A phone running older code (or sending nothing) is told to update: it shows
     * the message and reloads the page, which brings the current code.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ((int) $request->header(self::HEADER, '0') < self::VERSION) {
            return response()->json(['ok' => false, 'error' => 'update_required', 'message' => __('audit.errors.update_required')], 426);
        }

        return $next($request);
    }
}
