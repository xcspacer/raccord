<?php

namespace App\Enums;

/**
 * Where the field inspection of a box stands in the design office workflow (phase 1).
 */
enum AuditInspectionState: string
{
    case ToDo = 'to_do';
    case InProgress = 'in_progress';
    case Submitted = 'submitted';
    case Validated = 'validated';
    case Refused = 'refused';
    case ControlRequested = 'control_requested';

    public function label(): string
    {
        return __('enums.audit_inspection_states.'.$this->value);
    }

    /**
     * States that keep the responsible technician on the box: waiting for the decision, or to be done again.
     *
     * @return list<self>
     */
    public static function blocking(): array
    {
        return [self::Submitted, self::Refused, self::ControlRequested];
    }

    /**
     * States from which the responsible technician may submit the box.
     *
     * @return list<self>
     */
    public static function submittable(): array
    {
        return [self::InProgress, self::Refused, self::ControlRequested];
    }
}
