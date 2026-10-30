<?php

namespace App\Enums;

/**
 * Why a photo of the design office workflow has no position: the phone declared that no signal could be had (the
 * photo is never lost for a network problem), or a supervisor imported it from elsewhere. A refused permission is not
 * a reason: the location is then required.
 */
enum AuditGeoMissingReason: string
{
    case Network = 'network';
    case Timeout = 'timeout';
    case Unavailable = 'unavailable';
    case Imported = 'imported';

    public function label(): string
    {
        return __('enums.audit_geo_missing_reasons.'.$this->value);
    }

    /**
     * Reasons a phone may declare.
     *
     * @return list<self>
     */
    public static function declarable(): array
    {
        return [self::Network, self::Timeout, self::Unavailable];
    }
}
