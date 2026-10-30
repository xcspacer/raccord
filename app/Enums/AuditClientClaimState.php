<?php

namespace App\Enums;

/**
 * State of a client claim.
 */
enum AuditClientClaimState: string
{
    case Open = 'open';
    case Accepted = 'accepted';
    case Refused = 'refused';
    case Escalated = 'escalated';

    public function label(): string
    {
        return __('enums.audit_client_claim_states.'.$this->value);
    }

    /**
     * Still waiting for an answer: from the design office, or from a supervisor once escalated.
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::Open, self::Escalated], true);
    }
}
