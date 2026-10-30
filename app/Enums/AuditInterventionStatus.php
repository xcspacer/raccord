<?php

namespace App\Enums;

/**
 * State of a repair intervention planned by a supervisor (phase 2).
 */
enum AuditInterventionStatus: string
{
    case Planned = 'planned';
    case Submitted = 'submitted';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('enums.audit_intervention_statuses.'.$this->value);
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Planned, self::Submitted], true);
    }
}
