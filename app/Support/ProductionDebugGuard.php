<?php

namespace App\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;

/**
 * A production installation never runs in debug mode: error pages would show the code, the configuration and the
 * request to visitors. If APP_DEBUG was left on, it is switched off for the request and the mistake is logged as
 * critical so the hosting can fix the .env file.
 */
class ProductionDebugGuard
{
    public function __construct(private Application $app) {}

    public function enforce(): void
    {
        if (! $this->app->isProduction() || ! config('app.debug')) {
            return;
        }

        config(['app.debug' => false]);

        Log::critical('APP_DEBUG is on in production: debug mode was switched off. Set APP_DEBUG=false in the .env file.');
    }
}
