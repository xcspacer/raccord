<?php

namespace App\Enums;

/**
 * What happened to an inventory line, as recorded in its history.
 */
enum InventoryMovementType: string
{
    case Create = 'create';
    case Noted = 'noted';
    case Confirm = 'confirm';
    case Assign = 'assign';
    case Return = 'return';
    case Transfer = 'transfer';
    case Anomaly = 'anomaly';
    case Resolve = 'resolve';
    case Request = 'request';
    case RequestClose = 'request_close';
    case Edit = 'edit';
    case Attest = 'attest';
    case Photo = 'photo';
    case PhotoRemove = 'photo_remove';
    case PhotoReplace = 'photo_replace';
    case Delete = 'delete';
    case Restore = 'restore';

    public function label(): string
    {
        return __('enums.inventory_movement_types.'.$this->value);
    }
}
