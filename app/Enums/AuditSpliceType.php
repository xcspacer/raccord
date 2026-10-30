<?php

namespace App\Enums;

/**
 * What a fibre of a box becomes, as recorded during the recheck of a box against its plan: still waiting (not
 * spliced yet), spliced onto a fibre of cable B, or passing through the box without being cut.
 */
enum AuditSpliceType: string
{
    case Waiting = 'waiting';
    case Splice = 'splice';
    case Pass = 'pass';

    public function label(): string
    {
        return __('enums.audit_splice_types.'.$this->value);
    }

    /**
     * The type written in column E of the client's box plan (an empty cell means waiting); null for any other word.
     */
    public static function fromPlanWord(string $word): ?self
    {
        return match (mb_strtoupper(trim($word))) {
            '', 'ATTENTE' => self::Waiting,
            'EPISSURE' => self::Splice,
            'PASSAGE' => self::Pass,
            default => null,
        };
    }

    /**
     * The word written in column E of the RECNOK workbook (the client's contractual format).
     */
    public function planWord(): string
    {
        return match ($this) {
            self::Waiting => 'ATTENTE',
            self::Splice => 'EPISSURE',
            self::Pass => 'PASSAGE',
        };
    }
}
