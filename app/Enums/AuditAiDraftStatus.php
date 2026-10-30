<?php

namespace App\Enums;

/**
 * State of a text drafted by the AI for an occurrence; only an approved one can be published.
 */
enum AuditAiDraftStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return __('enums.audit_ai_draft_statuses.'.$this->value);
    }
}
