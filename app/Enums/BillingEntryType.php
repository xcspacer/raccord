<?php

namespace App\Enums;

/**
 * A billing entry is a breakdown (« Panne ») or works (« Travaux »).
 */
enum BillingEntryType: string
{
    case Breakdown = 'breakdown';
    case Works = 'works';

    public function label(): string
    {
        return __('enums.billing_entry_types.'.$this->value);
    }
}
