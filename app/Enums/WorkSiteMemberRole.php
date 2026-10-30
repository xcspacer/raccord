<?php

namespace App\Enums;

enum WorkSiteMemberRole: string
{
    case Lead = 'lead';
    case Partner = 'partner';

    public function label(): string
    {
        return __('enums.work_site_member_roles.'.$this->value);
    }
}
