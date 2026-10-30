<?php

namespace App\Enums;

/**
 * What a client raises on its portal about a closed audit.
 */
enum AuditClientClaimKind: string
{
    case ReserveDispute = 'reserve_dispute';
    case NewOccurrence = 'new_occurrence';

    public function label(): string
    {
        return __('enums.audit_client_claim_kinds.'.$this->value);
    }
}
