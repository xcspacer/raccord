<?php

/**
 * Router of the PHP built-in server used by the browser tests (see playwright.config.ts).
 *
 * When BROWSER_TEST_NOW is set, the application clock starts at that moment when the server starts
 * (BROWSER_TEST_EPOCH, a Unix time) and then runs at normal speed, so screenshots always show the same day
 * while sessions and rate limits keep working.
 */

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

$now = getenv('BROWSER_TEST_NOW');

if (is_string($now) && $now !== '') {
    require __DIR__.'/../../../vendor/autoload.php';

    $start = CarbonImmutable::parse($now);
    $epoch = (int) (getenv('BROWSER_TEST_EPOCH') ?: time());

    Carbon::setTestNow(fn () => $start->addSeconds(time() - $epoch));
}

return require __DIR__.'/../../../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';
