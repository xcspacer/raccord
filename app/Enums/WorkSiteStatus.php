<?php

namespace App\Enums;

enum WorkSiteStatus: string
{
    case Forecast = 'forecast';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('enums.work_site_statuses.'.$this->value);
    }
}
