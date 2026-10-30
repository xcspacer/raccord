<?php

namespace App\Enums;

/**
 * Phase of an audit in the design office workflow: inspection of every box, then mechanical repairs, then conformity,
 * reserves and closure. A phase only ever moves forward.
 */
enum AuditWorkflowPhase: int
{
    case Inspection = 1;
    case Repairs = 2;
    case Closure = 3;

    public function label(): string
    {
        return __('enums.audit_workflow_phases.'.$this->value);
    }
}
