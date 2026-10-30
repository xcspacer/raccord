<?php

namespace App\Enums;

/**
 * Physical condition of an inventory line.
 */
enum InventoryCondition: string
{
    case New = 'new';
    case Good = 'good';
    case Worn = 'worn';
    case Defective = 'defective';
    case Unknown = 'unknown';

    public function label(): string
    {
        return __('enums.inventory_conditions.'.$this->value);
    }
}
