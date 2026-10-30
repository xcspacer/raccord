<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;

/**
 * Trusts the forwarded client address, host and scheme only from the reverse proxies of this installation
 * (TRUSTED_PROXIES), so rate limits and logs see the visitor's address instead of the proxy's.
 */
class TrustProxies extends Middleware
{
    /**
     * @return array<int, string>|string|null
     */
    protected function proxies()
    {
        $configured = config('platform.trusted_proxies');

        return is_string($configured) && trim($configured) !== '' ? trim($configured) : parent::proxies();
    }
}
