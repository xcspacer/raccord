<?php

namespace App\Enums;

/**
 * Who declared an occurrence: the field team, or the client on its portal.
 */
enum AuditOccurrenceSource: string
{
    case Field = 'field';
    case Client = 'client';

    public function label(): string
    {
        return __('enums.audit_occurrence_sources.'.$this->value);
    }
}
