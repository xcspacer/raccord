<?php

namespace App\Enums;

/**
 * Photo sections asked for a box on a pole (before and after are older sections shown only when they hold photos).
 */
enum AuditPoleSection: string
{
    case Whole = 'whole';
    case Head = 'head';
    case Label = 'label';
    case Conduit = 'conduit';
    case Before = 'before';
    case After = 'after';

    public function label(): string
    {
        return __('enums.audit_pole_sections.'.$this->value);
    }

    /**
     * Sections a technician is asked to photograph.
     *
     * @return list<self>
     */
    public static function asked(): array
    {
        return [self::Whole, self::Head, self::Label, self::Conduit];
    }
}
