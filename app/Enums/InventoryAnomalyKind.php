<?php

namespace App\Enums;

/**
 * Kind of anomaly declared on an inventory line.
 */
enum InventoryAnomalyKind: string
{
    case Lost = 'lost';
    case Damaged = 'damaged';
    case Missing = 'missing';

    public function label(): string
    {
        return __('enums.inventory_anomaly_kinds.'.$this->value);
    }
}
