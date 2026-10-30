<?php

namespace App\Enums;

/**
 * Kind of reserve accepted by a supervisor: kept open for a follow-up, or closed administratively.
 */
enum AuditReserveKind: string
{
    case FollowUp = 'follow_up';
    case AdministrativeClosure = 'administrative_closure';

    public function label(): string
    {
        return __('enums.audit_reserve_kinds.'.$this->value);
    }
}
