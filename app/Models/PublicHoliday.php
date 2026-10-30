<?php

namespace App\Models;

use App\Enums\HolidayRule;
use Carbon\CarbonImmutable;
use Database\Factories\PublicHolidayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property HolidayRule $rule
 * @property int|null $month
 * @property int|null $day
 * @property int|null $easter_offset
 * @property Carbon|null $date
 */
#[Fillable(['name', 'rule', 'month', 'day', 'easter_offset', 'date'])]
class PublicHoliday extends Model
{
    /** @use HasFactory<PublicHolidayFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rule' => HolidayRule::class,
            'date' => 'date',
        ];
    }

    /**
     * Day the holiday falls on in the given year, or null when it does not occur that year.
     */
    public function occurrenceIn(int $year): ?CarbonImmutable
    {
        return match ($this->rule) {
            HolidayRule::FixedDate => checkdate((int) $this->month, (int) $this->day, $year) ? CarbonImmutable::create($year, (int) $this->month, (int) $this->day) : null,
            HolidayRule::EasterOffset => self::easterSunday($year)->addDays((int) $this->easter_offset),
            HolidayRule::SingleDate => $this->date?->year === $year ? $this->date->toImmutable()->startOfDay() : null,
        };
    }

    /**
     * Easter Sunday (Meeus/Jones/Butcher algorithm, Gregorian calendar).
     */
    public static function easterSunday(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day);
    }
}
