<?php

namespace App\Enums;

/**
 * State of a ticket priced flat once closed: still frozen, or closed.
 */
enum BillingClosureStatus: string
{
    case Frozen = 'frozen';
    case Closed = 'closed';

    public function label(): string
    {
        return __('enums.billing_closure_statuses.'.$this->value);
    }
}
