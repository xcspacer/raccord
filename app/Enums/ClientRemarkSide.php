<?php

namespace App\Enums;

/**
 * Who wrote a reply in a client portal note thread: the client or the company's staff.
 */
enum ClientRemarkSide: string
{
    case Client = 'client';
    case Staff = 'staff';

    public function label(): string
    {
        return __('enums.client_remark_sides.'.$this->value);
    }
}
