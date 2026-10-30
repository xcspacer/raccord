<?php

namespace App\Enums;

enum VehicleDamageType: string
{
    case Scratch = 'scratch';
    case Dent = 'dent';
    case CrackedWindscreen = 'cracked_windscreen';
    case CrackedWindow = 'cracked_window';
    case DamagedTyre = 'damaged_tyre';
    case BrokenMirror = 'broken_mirror';
    case BrokenLight = 'broken_light';
    case DamagedBumper = 'damaged_bumper';
    case DentedBodywork = 'dented_bodywork';
    case ChippedPaint = 'chipped_paint';
    case StainedInterior = 'stained_interior';
    case Other = 'other';

    public function label(): string
    {
        return __('enums.vehicle_damage_types.'.$this->value);
    }
}
