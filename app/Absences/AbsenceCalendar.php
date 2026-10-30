<?php

namespace App\Absences;

use App\Enums\AbsenceType;
use App\Models\Absence;
use App\Support\PublicHolidays;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;

/**
 * Absences of one person for one month, as shown on the absences page and the user cards:
 * displayed periods (same-type days separated only by non-working days are merged), monthly
 * totals per type and today's status.
 */
class AbsenceCalendar
{
    /** Days fetched around the month so periods crossing its edges are complete. */
    private const int MARGIN_DAYS = 62;

    public function __construct(private PublicHolidays $holidays) {}

    /**
     * @return array{periods: list<array{type: AbsenceType, start: CarbonImmutable, end: CarbonImmutable, days: int}>, counts: array<string, int>, total: int, today: ?AbsenceType}
     */
    public function forMonth(int $userId, CarbonImmutable $month): array
    {
        $monthStart = $month->startOfMonth();
        $monthEnd = $month->endOfMonth()->startOfDay();
        $windowStart = $monthStart->subDays(self::MARGIN_DAYS);
        $windowEnd = $monthEnd->addDays(self::MARGIN_DAYS);

        $byDay = $this->daysByType($userId, $windowStart, $windowEnd);

        $counts = array_fill_keys(array_map(fn (AbsenceType $type) => $type->value, AbsenceType::cases()), 0);
        foreach (CarbonPeriod::create($monthStart, $monthEnd) as $day) {
            if ($type = $byDay[$day->toDateString()] ?? null) {
                $counts[$type->value]++;
            }
        }

        $periods = array_values(array_filter(
            $this->mergePeriods($byDay),
            fn (array $period) => $period['end']->gte($monthStart) && $period['start']->lte($monthEnd),
        ));
        usort($periods, fn (array $a, array $b) => $b['start'] <=> $a['start']);

        return [
            'periods' => $periods,
            'counts' => $counts,
            'total' => array_sum($counts),
            'today' => $byDay[CarbonImmutable::today(config('platform.timezone'))->toDateString()] ?? null,
        ];
    }

    /**
     * Merged absence periods overlapping a window, for several people with a single query.
     *
     * @param  list<int>  $userIds
     * @return array<int, list<array{type: AbsenceType, start: CarbonImmutable, end: CarbonImmutable, days: int}>>
     */
    public function periodsForUsers(array $userIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($userIds === []) {
            return [];
        }

        $windowStart = $from->subDays(self::MARGIN_DAYS);
        $windowEnd = $to->addDays(self::MARGIN_DAYS);
        $byUser = [];

        Absence::query()
            ->whereIn('user_id', $userIds)
            ->whereDate('starts_on', '<=', $windowEnd)
            ->whereDate('ends_on', '>=', $windowStart)
            ->orderBy('id')
            ->get()
            ->each(function (Absence $absence) use (&$byUser): void {
                foreach (CarbonPeriod::create($absence->starts_on, $absence->ends_on) as $day) {
                    $byUser[$absence->user_id][$day->toDateString()] = $absence->type;
                }
            });

        $result = [];

        foreach ($byUser as $userId => $byDay) {
            ksort($byDay);
            $periods = array_values(array_filter(
                $this->mergePeriods($byDay),
                fn (array $period) => $period['end']->gte($from) && $period['start']->lte($to),
            ));

            if ($periods !== []) {
                $result[$userId] = $periods;
            }
        }

        return $result;
    }

    /**
     * One absence type per calendar day (the most recently recorded one wins on overlaps).
     *
     * @return array<string, AbsenceType>
     */
    private function daysByType(int $userId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $byDay = [];

        Absence::query()
            ->where('user_id', $userId)
            ->whereDate('starts_on', '<=', $to)
            ->whereDate('ends_on', '>=', $from)
            ->orderBy('id')
            ->get()
            ->each(function (Absence $absence) use (&$byDay): void {
                foreach (CarbonPeriod::create($absence->starts_on, $absence->ends_on) as $day) {
                    $byDay[$day->toDateString()] = $absence->type;
                }
            });

        ksort($byDay);

        return $byDay;
    }

    /**
     * @param  array<string, AbsenceType>  $byDay
     * @return list<array{type: AbsenceType, start: CarbonImmutable, end: CarbonImmutable, days: int}>
     */
    private function mergePeriods(array $byDay): array
    {
        $periods = [];
        $current = null;

        foreach ($byDay as $date => $type) {
            $day = CarbonImmutable::parse($date);

            if ($current !== null && $current['type'] === $type && $this->onlyNonWorkingDaysBetween($current['end'], $day, $byDay)) {
                $current['end'] = $day;

                continue;
            }

            if ($current !== null) {
                $periods[] = $current;
            }

            $current = ['type' => $type, 'start' => $day, 'end' => $day];
        }

        if ($current !== null) {
            $periods[] = $current;
        }

        return array_map(fn (array $period) => [...$period, 'days' => (int) $period['start']->diffInDays($period['end']) + 1], $periods);
    }

    /**
     * @param  array<string, AbsenceType>  $byDay
     */
    private function onlyNonWorkingDaysBetween(CarbonImmutable $from, CarbonImmutable $to, array $byDay): bool
    {
        for ($day = $from->addDay(); $day->lt($to); $day = $day->addDay()) {
            if (isset($byDay[$day->toDateString()]) || $this->holidays->isWorkingDay($day)) {
                return false;
            }
        }

        return true;
    }
}
