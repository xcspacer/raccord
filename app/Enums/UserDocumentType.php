<?php

namespace App\Enums;

enum UserDocumentType: string
{
    case BtpCard = 'btp_card';
    case DrivingLicence = 'driving_licence';

    public function label(): string
    {
        return __('enums.user_document_types.'.$this->value);
    }
}
