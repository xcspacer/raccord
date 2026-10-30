<?php

namespace App\Enums;

/**
 * The plans attached to a cabinet audit: the drawer plan of the cabinet and the plans of each box.
 */
enum AuditPlanKind: string
{
    case Drawer = 'drawer';
    case Box = 'box';

    public function label(): string
    {
        return __('enums.audit_plan_kinds.'.$this->value);
    }
}
