<?php

namespace App\Enums;

enum AuditMode: string
{
    case Optical = 'optical';
    case Full = 'full';

    public function label(): string
    {
        return __('enums.audit_modes.'.$this->value);
    }
}
