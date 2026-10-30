<?php

namespace App\Enums;

/**
 * Planning behaviour of an agency: detailed crews and manager, or one-day work sites.
 */
enum PlanningMode: string
{
    case Detailed = 'detailed';
    case Daily = 'daily';

    public function label(): string
    {
        return __('enums.planning_modes.'.$this->value);
    }
}
