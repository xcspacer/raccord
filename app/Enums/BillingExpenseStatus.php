<?php

namespace App\Enums;

/**
 * Review state of an expense: waiting, approved or rejected (final).
 */
enum BillingExpenseStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return __('enums.billing_expense_statuses.'.$this->value);
    }
}
