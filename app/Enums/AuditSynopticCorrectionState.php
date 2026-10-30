<?php

namespace App\Enums;

/**
 * State of a correction of the synoptic proposed by a technician: open until the design office includes it in a
 * consolidated version of the synoptic or rejects it.
 */
enum AuditSynopticCorrectionState: string
{
    case Open = 'open';
    case Included = 'included';
    case Rejected = 'rejected';

    public function label(): string
    {
        return __('enums.audit_synoptic_correction_states.'.$this->value);
    }
}
