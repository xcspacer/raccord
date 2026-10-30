<?php

namespace App\Enums;

enum ProjectType: string
{
    case Swap = 'swap';
    case Nro = 'nro';
    case Audit = 'audit';

    public function label(): string
    {
        return __('enums.project_types.'.$this->value);
    }
}
