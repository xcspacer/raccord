<?php

namespace App\Enums;

/**
 * How the data of an equipment card was obtained: being read by the AI, read with confidence, read with
 * doubts (to check), or typed by the technician.
 */
enum EquipmentReadStatus: string
{
    case Reading = 'reading';
    case Ok = 'ok';
    case Check = 'check';
    case Manual = 'manual';

    public function label(): string
    {
        return __('enums.equipment_read_statuses.'.$this->value);
    }
}
