<?php

namespace App\Enums;

/**
 * Where a position of a box comes from; the official one is approved by the design office.
 */
enum AuditPositionSource: string
{
    case MapOrigin = 'map_origin';
    case Field = 'field';
    case Proposed = 'proposed';
    case Official = 'official';

    public function label(): string
    {
        return __('enums.audit_position_sources.'.$this->value);
    }
}
