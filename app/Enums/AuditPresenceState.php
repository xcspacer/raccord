<?php

namespace App\Enums;

/**
 * Where a technician says they are: on the way to a box, or on it.
 */
enum AuditPresenceState: string
{
    case EnRoute = 'en_route';
    case OnBox = 'on_box';

    public function label(): string
    {
        return __('enums.audit_presence_states.'.$this->value);
    }
}
