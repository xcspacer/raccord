<?php

namespace App\Enums;

/**
 * Nature of an anomaly: optical ones are corrected during the inspection, mechanical ones in the repairs phase.
 */
enum AuditAnomalyNature: string
{
    case Optical = 'optical';
    case Mechanical = 'mechanical';

    public function label(): string
    {
        return __('enums.audit_anomaly_natures.'.$this->value);
    }
}
