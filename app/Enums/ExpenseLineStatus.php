<?php

namespace App\Enums;

/**
 * How an expense line was filled in: ticket being read by the AI, read with confidence, read with doubts
 * (to check), or typed by the person.
 */
enum ExpenseLineStatus: string
{
    case Reading = 'reading';
    case Ok = 'ok';
    case Check = 'check';
    case Manual = 'manual';

    public function label(): string
    {
        return __('enums.expense_line_statuses.'.$this->value);
    }
}
