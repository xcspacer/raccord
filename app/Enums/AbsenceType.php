<?php

namespace App\Enums;

enum AbsenceType: string
{
    case Absent = 'absent';
    case Sick = 'sick';
    case Leave = 'leave';

    public function label(): string
    {
        return __('enums.absence_types.'.$this->value);
    }
}
