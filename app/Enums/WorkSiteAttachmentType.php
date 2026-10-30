<?php

namespace App\Enums;

enum WorkSiteAttachmentType: string
{
    case Kmz = 'kmz';
    case Synoptic = 'synoptic';
    case Other = 'other';

    public function label(): string
    {
        return __('enums.work_site_attachment_types.'.$this->value);
    }
}
