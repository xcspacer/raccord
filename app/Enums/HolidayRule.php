<?php

namespace App\Enums;

/**
 * How a public holiday falls each year.
 */
enum HolidayRule: string
{
    case FixedDate = 'fixed_date';
    case EasterOffset = 'easter_offset';
    case SingleDate = 'single_date';

    public function label(): string
    {
        return __('enums.holiday_rules.'.$this->value);
    }
}
