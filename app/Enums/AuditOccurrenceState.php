<?php

namespace App\Enums;

/**
 * Life cycle of an occurrence (one distinct anomaly found on a box).
 */
enum AuditOccurrenceState: string
{
    case Detected = 'detected';
    case ToRepair = 'to_repair';
    case Corrected = 'corrected';
    case Validated = 'validated';
    case ReserveAccepted = 'reserve_accepted';
    case Refused = 'refused';

    public function label(): string
    {
        return __('enums.audit_occurrence_states.'.$this->value);
    }

    /**
     * Closed occurrences no longer accept photos or changes.
     */
    public function isClosed(): bool
    {
        return in_array($this, [self::Validated, self::ReserveAccepted], true);
    }

    /**
     * The life cycle: detected → to repair (mechanical, phase 2) → corrected → validated by the design office, with a
     * refused repair going back to be corrected again and an accepted reserve closing an anomaly that is not
     * corrected. Validated and accepted reserves are final.
     *
     * @return list<self>
     */
    public function next(): array
    {
        return match ($this) {
            self::Detected => [self::ToRepair, self::Corrected, self::ReserveAccepted],
            self::ToRepair => [self::Corrected, self::ReserveAccepted],
            self::Corrected => [self::Validated, self::Refused],
            self::Refused => [self::Corrected, self::ReserveAccepted],
            self::Validated, self::ReserveAccepted => [],
        };
    }

    public function canBecome(self $state): bool
    {
        return in_array($state, $this->next(), true);
    }

    /**
     * States in which the anomaly is still to be dealt with.
     *
     * @return list<self>
     */
    public static function toDeal(): array
    {
        return [self::Detected, self::ToRepair, self::Refused];
    }
}
