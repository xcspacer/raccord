<?php

namespace App\Billing\Export;

use App\Models\BillingEntry;
use Closure;

/**
 * One column of an entries sheet: its header, width (characters), value for an entry and whether the technician's
 * footer adds it up.
 */
final readonly class SheetColumn
{
    /**
     * @param  Closure(BillingEntry): (string|int|float|null)  $value
     */
    public function __construct(public string $header, public int $width, public Closure $value, public bool $summed = false) {}
}
