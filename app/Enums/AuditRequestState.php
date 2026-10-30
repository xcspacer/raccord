<?php

namespace App\Enums;

/**
 * State of a validation request in the design office queue.
 */
enum AuditRequestState: string
{
    case Queued = 'queued';
    case Assigned = 'assigned';
    case InReview = 'in_review';
    case Decided = 'decided';

    public function label(): string
    {
        return __('enums.audit_request_states.'.$this->value);
    }

    /**
     * Open requests count in the load of their reviewer.
     *
     * @return list<self>
     */
    public static function open(): array
    {
        return [self::Queued, self::Assigned, self::InReview];
    }
}
