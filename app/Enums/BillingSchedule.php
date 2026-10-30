<?php

namespace App\Enums;

/**
 * Business hours (« HO ») or off hours (« HNO »).
 */
enum BillingSchedule: string
{
    case BusinessHours = 'business_hours';
    case OffHours = 'off_hours';

    public function label(): string
    {
        return __('enums.billing_schedules.'.$this->value);
    }

    public function shortLabel(): string
    {
        return __('enums.billing_schedules_short.'.$this->value);
    }
}
