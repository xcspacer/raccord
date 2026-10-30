<?php

namespace App\Enums;

/**
 * The two photo sets of a media library folder: before and after the works.
 */
enum MediaPhase: string
{
    case Before = 'before';
    case After = 'after';

    public function label(): string
    {
        return __('enums.media_phases.'.$this->value);
    }
}
