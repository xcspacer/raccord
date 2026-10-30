<?php

namespace App\Billing;

use App\Enums\BillingExpenseCategory;

/**
 * Checks an expense before it is saved, in the legacy order: the first problem found is the message shown.
 *
 * @phpstan-type ExpenseInput array{date: string, category: string, categoryOther: string, amount: string, mileage: string, comment: string, photos: int}
 */
class ExpenseRules
{
    /**
     * @param  ExpenseInput  $input
     */
    public function firstError(array $input): ?string
    {
        $category = BillingExpenseCategory::tryFrom($input['category']);

        return match (true) {
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['date']) !== 1 => __('billing.expenses.errors.date'),
            $category === null => __('billing.expenses.errors.category'),
            $category === BillingExpenseCategory::Other && trim($input['categoryOther']) === '' => __('billing.expenses.errors.other'),
            self::number($input['amount']) === null || self::number($input['amount']) <= 0 => __('billing.expenses.errors.amount'),
            $category === BillingExpenseCategory::Fuel && (self::number($input['mileage']) === null || self::number($input['mileage']) <= 0) => __('billing.expenses.errors.mileage'),
            $input['photos'] === 0 => __('billing.expenses.errors.photo'),
            default => null,
        };
    }

    /**
     * A number typed with a comma or a dot, or null.
     */
    public static function number(string $value): ?float
    {
        $value = str_replace([',', ' '], ['.', ''], trim($value));

        return is_numeric($value) ? (float) $value : null;
    }
}
