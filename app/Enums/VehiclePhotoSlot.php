<?php

namespace App\Enums;

/**
 * The five photos of the monthly vehicle inspection.
 */
enum VehiclePhotoSlot: string
{
    case Front = 'front';
    case Left = 'left';
    case Right = 'right';
    case Rear = 'rear';
    case Dashboard = 'dashboard';

    public function label(): string
    {
        return __('enums.vehicle_photo_slots.'.$this->value);
    }
}
