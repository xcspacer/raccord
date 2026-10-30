<?php

namespace App\Enums;

/**
 * Photos of works are taken before or after the intervention.
 */
enum BillingPhotoCategory: string
{
    case Before = 'before';
    case After = 'after';

    public function label(): string
    {
        return __('enums.billing_photo_categories.'.$this->value);
    }
}
