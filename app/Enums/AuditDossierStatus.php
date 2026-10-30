<?php

namespace App\Enums;

/**
 * Generation state of an official dossier version; a published one never changes.
 */
enum AuditDossierStatus: string
{
    case Pending = 'pending';
    case Generating = 'generating';
    case Published = 'published';
    case Failed = 'failed';

    public function label(): string
    {
        return __('enums.audit_dossier_statuses.'.$this->value);
    }

    public function isInProgress(): bool
    {
        return in_array($this, [self::Pending, self::Generating], true);
    }
}
