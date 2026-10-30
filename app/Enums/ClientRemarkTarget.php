<?php

namespace App\Enums;

/**
 * What a client portal note is about: a box, a control point, or the whole audit.
 */
enum ClientRemarkTarget: string
{
    case Box = 'box';
    case ControlPoint = 'control_point';
    case Audit = 'audit';

    public function label(): string
    {
        return __('enums.client_remark_targets.'.$this->value);
    }
}
