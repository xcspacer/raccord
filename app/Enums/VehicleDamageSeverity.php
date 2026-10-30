<?php

namespace App\Enums;

/**
 * Severity of a new damage found by the AI comparison of two photos of the same vehicle angle.
 */
enum VehicleDamageSeverity: string
{
    case None = 'none';
    case Minor = 'minor';
    case Severe = 'severe';

    public function label(): string
    {
        return __('enums.vehicle_damage_severities.'.$this->value);
    }
}
