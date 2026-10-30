<?php

namespace App\Enums;

/**
 * Final or partial state declared on a box.
 */
enum AuditBoxStatus: string
{
    case Ok = 'ok';
    case Anomaly = 'anomaly';
    case NotInstalled = 'not_installed';
    case Partial = 'partial';

    public function label(): string
    {
        return __('enums.audit_box_statuses.'.$this->value);
    }

    /**
     * Whether the state closes the box (a returned box is never final).
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::Ok, self::Anomaly, self::NotInstalled], true);
    }
}
