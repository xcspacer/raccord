<?php

namespace App\Enums;

/**
 * Where an inventory line stands: in stock, noted on a person but not yet confirmed, officially handed over,
 * in repair, lost, damaged, or out of service.
 */
enum InventoryStatus: string
{
    case Stock = 'stock';
    case ToConfirm = 'to_confirm';
    case Assigned = 'assigned';
    case Repair = 'repair';
    case Lost = 'lost';
    case Damaged = 'damaged';
    case Retired = 'retired';

    public function label(): string
    {
        return __('enums.inventory_statuses.'.$this->value);
    }

    /**
     * Whether an anomaly or a problem is attached to the line.
     */
    public function isProblem(): bool
    {
        return in_array($this, [self::Lost, self::Damaged, self::Repair], true);
    }
}
