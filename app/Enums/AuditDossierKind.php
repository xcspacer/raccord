<?php

namespace App\Enums;

/**
 * First official dossier of an audit, or a later version.
 */
enum AuditDossierKind: string
{
    case Initial = 'initial';
    case Update = 'update';

    public function label(): string
    {
        return __('enums.audit_dossier_kinds.'.$this->value);
    }
}
