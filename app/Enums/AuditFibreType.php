<?php

namespace App\Enums;

/**
 * Type of optical fibre.
 */
enum AuditFibreType: string
{
    case G652 = 'g652';
    case G657 = 'g657';

    public function label(): string
    {
        return __('enums.audit_fibre_types.'.$this->value);
    }
}
