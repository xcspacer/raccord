<?php

namespace App\Enums;

/**
 * Where a box of the audit comes from: the cabinet diagram, a cross reference, or added by a technician or a manager.
 */
enum AuditBoxSource: string
{
    case Synoptic = 'synoptic';
    case CrossReference = 'cross_reference';
    case Technician = 'technician';
    case Manager = 'manager';

    public function label(): string
    {
        return __('enums.audit_box_sources.'.$this->value);
    }
}
