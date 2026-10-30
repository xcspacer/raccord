<?php

namespace App\Enums;

/**
 * Photo folders of a box.
 */
enum AuditFolder: string
{
    case Surroundings = 'surroundings';
    case FacadeSurroundings = 'facade_surroundings';
    case Interior = 'interior';
    case Closed = 'closed';
    case Open = 'open';
    case Labels = 'labels';
    case Entries = 'entries';
    case Storage = 'storage';
    case Placed = 'placed';
    case Cassettes = 'cassettes';
    case Tco = 'tco';
    case Lashing = 'lashing';
    case Recheck = 'recheck';
    case Pole = 'pole';
    case Before = 'before';
    case Fixing = 'fixing';

    public function label(): string
    {
        return __('enums.audit_folders.'.$this->value);
    }
}
