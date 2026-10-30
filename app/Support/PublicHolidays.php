<?php

namespace App\Support;

use App\Models\PublicHoliday;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * Public holidays of the installation (edited on « Jours fériés »), used to decide working days.
 */
class PublicHolidays
{
    /** @var Collection<int, PublicHoliday>|null */
    private ?Collection $holidays = null;

    /** @var array<int, array<string, true>> */
    private array $byYear = [];

    public function isHoliday(CarbonInterface $day): bool
    {
        return isset($this->forYear($day->year)[$day->toDateString()]);
    }

    public function isWorkingDay(CarbonInterface $day): bool
    {
        return ! $day->isWeekend() && ! $this->isHoliday($day);
    }

    /**
     * @return array<string, true>
     */
    public function forYear(int $year): array
    {
        if (isset($this->byYear[$year])) {
            return $this->byYear[$year];
        }

        $this->holidays ??= PublicHoliday::query()->get();

        $days = $this->holidays
            ->map(fn (PublicHoliday $holiday) => $holiday->occurrenceIn($year)?->toDateString())
            ->filter()
            ->all();

        return $this->byYear[$year] = array_fill_keys($days, true);
    }
}
