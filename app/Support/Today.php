<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Today's date in the company's calendar (Paris), as midnight of that date in the application timezone,
 * so it compares correctly with dates read from the database (work site and absence days).
 */
class Today
{
    public static function date(): CarbonImmutable
    {
        return CarbonImmutable::parse(CarbonImmutable::today(config('platform.timezone'))->toDateString());
    }
}
