<?php

namespace App\Enums;

/**
 * What a box is fixed on: a chamber, a pole or a facade.
 */
enum AuditSupport: string
{
    case Chamber = 'chamber';
    case Pole = 'pole';
    case Facade = 'facade';

    public function label(): string
    {
        return __('enums.audit_supports.'.$this->value);
    }
}
