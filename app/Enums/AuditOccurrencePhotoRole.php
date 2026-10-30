<?php

namespace App\Enums;

/**
 * Role of a photo of a box in an occurrence (the file is never duplicated).
 */
enum AuditOccurrencePhotoRole: string
{
    case Before = 'before';
    case After = 'after';
    case Other = 'other';

    public function label(): string
    {
        return __('enums.audit_occurrence_photo_roles.'.$this->value);
    }
}
