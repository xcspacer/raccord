<?php

namespace App\Enums;

/**
 * Kind of box audited at a cabinet: splice closure (BPE), termination box (PBO) or straight joint (JD).
 */
enum AuditBoxKind: string
{
    case Bpe = 'bpe';
    case Pbo = 'pbo';
    case Joint = 'jd';

    public function label(): string
    {
        return __('enums.audit_box_kinds.'.$this->value);
    }
}
