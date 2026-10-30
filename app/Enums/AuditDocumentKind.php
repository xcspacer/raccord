<?php

namespace App\Enums;

/**
 * Versioned documents of an audit in the design office workflow.
 */
enum AuditDocumentKind: string
{
    case Synoptic = 'synoptic';
    case Map = 'map';

    public function label(): string
    {
        return __('enums.audit_document_kinds.'.$this->value);
    }
}
