<?php

namespace App\Enums;

/**
 * Kind of expense a technician declares.
 */
enum BillingExpenseCategory: string
{
    case Fuel = 'fuel';
    case Toll = 'toll';
    case Meal = 'meal';
    case Parking = 'parking';
    case Lodging = 'lodging';
    case Other = 'other';

    public function label(): string
    {
        return __('enums.billing_expense_categories.'.$this->value);
    }
}
