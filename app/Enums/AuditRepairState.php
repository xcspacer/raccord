<?php

namespace App\Enums;

/**
 * Where the repairs of a box stand in the design office workflow (phase 2).
 */
enum AuditRepairState: string
{
    case None = 'none';
    case Planned = 'planned';
    case Submitted = 'submitted';
    case Refused = 'refused';
    case Validated = 'validated';

    public function label(): string
    {
        return __('enums.audit_repair_states.'.$this->value);
    }

    /**
     * States that keep the responsible technician on the box.
     *
     * @return list<self>
     */
    public static function blocking(): array
    {
        return [self::Submitted, self::Refused];
    }
}
