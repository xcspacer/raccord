<?php

namespace App\Enums;

/**
 * What a holder asks the manager for.
 */
enum InventoryRequestKind: string
{
    case Return = 'return';
    case Replacement = 'replacement';

    public function label(): string
    {
        return __('enums.inventory_request_kinds.'.$this->value);
    }
}
