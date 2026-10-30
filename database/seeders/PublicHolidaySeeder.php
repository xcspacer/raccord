<?php

namespace Database\Seeders;

use App\Enums\HolidayRule;
use App\Models\PublicHoliday;
use Illuminate\Database\Seeder;

/**
 * Starting list of public holidays (French calendar, as the legacy platform), only on an empty table:
 * the administrator then edits, removes or adds holidays on « Jours fériés ».
 */
class PublicHolidaySeeder extends Seeder
{
    public function run(): void
    {
        if (PublicHoliday::query()->exists()) {
            return;
        }

        $fixed = ['new_year' => [1, 1], 'labour_day' => [5, 1], 'victory_day' => [5, 8], 'national_day' => [7, 14], 'assumption' => [8, 15], 'all_saints' => [11, 1], 'armistice' => [11, 11], 'christmas' => [12, 25]];
        $easter = ['easter_monday' => 1, 'ascension' => 39, 'whit_monday' => 50];

        foreach ($fixed as $key => [$month, $day]) {
            PublicHoliday::query()->create(['name' => __('holidays.defaults.'.$key), 'rule' => HolidayRule::FixedDate, 'month' => $month, 'day' => $day]);
        }

        foreach ($easter as $key => $offset) {
            PublicHoliday::query()->create(['name' => __('holidays.defaults.'.$key), 'rule' => HolidayRule::EasterOffset, 'easter_offset' => $offset]);
        }
    }
}
