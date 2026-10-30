<?php

namespace App\Enums;

/**
 * The lists of articles a technician picks from in a report: for a box in a chamber, for a box on a pole or a
 * facade, and for the cabinet itself.
 */
enum AuditCatalogList: string
{
    case Chamber = 'chamber';
    case Pole = 'pole';
    case Cabinet = 'cabinet';

    public function label(): string
    {
        return __('enums.audit_catalog_lists.'.$this->value);
    }
}
