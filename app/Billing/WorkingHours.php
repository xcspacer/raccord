<?php

namespace App\Billing;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Splits an interval into business hours (06:00-21:00 every day) and off hours (the rest), across days.
 */
class WorkingHours
{
    public const int DAY_STARTS = 6;

    public const int DAY_ENDS = 21;

    /**
     * Minutes in business hours and in off hours; nothing when the interval is empty or reversed.
     *
     * @return array{business: int, off: int}
     */
    public static function split(?CarbonInterface $start, ?CarbonInterface $end): array
    {
        if ($start === null || $end === null || $end->lte($start)) {
            return ['business' => 0, 'off' => 0];
        }

        $from = CarbonImmutable::instance($start);
        $to = CarbonImmutable::instance($end);
        $total = (int) $from->diffInMinutes($to);
        $business = 0;

        for ($day = $from->startOfDay(); $day->lt($to); $day = $day->addDay()) {
            $open = $day->setTime(self::DAY_STARTS, 0);
            $close = $day->setTime(self::DAY_ENDS, 0);
            $overlapStart = $from->max($open);
            $overlapEnd = $to->min($close);

            if ($overlapEnd->gt($overlapStart)) {
                $business += (int) $overlapStart->diffInMinutes($overlapEnd);
            }
        }

        return ['business' => $business, 'off' => $total - $business];
    }
}
