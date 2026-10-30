<?php

namespace App\Enums;

/**
 * What a technician submits to the design office: the inspection of a box, or its repairs.
 */
enum AuditSubmissionKind: string
{
    case Inspection = 'inspection';
    case Repair = 'repair';

    public function label(): string
    {
        return __('enums.audit_submission_kinds.'.$this->value);
    }
}
