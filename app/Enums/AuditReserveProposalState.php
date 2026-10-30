<?php

namespace App\Enums;

/**
 * State of a reserve proposed by the design office or a supervisor.
 */
enum AuditReserveProposalState: string
{
    case Open = 'open';
    case Accepted = 'accepted';
    case Rejected = 'rejected';

    public function label(): string
    {
        return __('enums.audit_reserve_proposal_states.'.$this->value);
    }
}
