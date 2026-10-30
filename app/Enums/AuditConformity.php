<?php

namespace App\Enums;

/**
 * Technical conformity of a box, derived from its validated inspection and open occurrences (phase 3); also the result of an occurrence on each box it impacts.
 */
enum AuditConformity: string
{
    case NotAssessed = 'not_assessed';
    case Conforming = 'conforming';
    case ConformingWithReserve = 'conforming_with_reserve';
    case NonConforming = 'non_conforming';

    public function label(): string
    {
        return __('enums.audit_conformities.'.$this->value);
    }
}
