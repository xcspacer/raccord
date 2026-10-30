<?php

namespace App\Enums;

/**
 * Where a client portal note stands: waiting for the company, answered by the company, or closed.
 */
enum ClientRemarkStatus: string
{
    case Open = 'open';
    case Answered = 'answered';
    case Closed = 'closed';

    public function label(): string
    {
        return __('enums.client_remark_statuses.'.$this->value);
    }
}
