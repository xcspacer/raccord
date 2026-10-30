<?php

namespace App\Enums;

/**
 * How an audit is validated: at the end, cabinet by cabinet (standard), or box by box by the design office.
 */
enum AuditWorkflow: string
{
    case Standard = 'standard';
    case DesignOffice = 'design_office';

    public function label(): string
    {
        return __('enums.audit_workflows.'.$this->value);
    }
}
