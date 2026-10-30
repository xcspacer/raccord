<?php

namespace App\Support;

use Illuminate\Support\Number;

/**
 * Amounts of the expense reports: tax-inclusive euros kept as cents.
 */
class Money
{
    public static function format(int $cents): string
    {
        return Number::currency($cents / 100, 'EUR', app()->getLocale());
    }

    /**
     * Cents of what a person typed (comma or point as decimal separator), or null when it is not an amount.
     */
    public static function parse(string $input): ?int
    {
        $input = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim($input));

        if ($input === '' || preg_match('/^\d{1,6}(\.\d{1,2})?$/', $input) !== 1) {
            return null;
        }

        return (int) round(((float) $input) * 100);
    }

    public static function input(int $cents): string
    {
        return $cents === 0 ? '' : number_format($cents / 100, 2, '.', '');
    }
}
