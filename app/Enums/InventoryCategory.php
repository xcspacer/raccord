<?php

namespace App\Enums;

/**
 * Categories proposed when recording equipment.
 */
enum InventoryCategory: string
{
    case Welder = 'welder';
    case Measurement = 'measurement';
    case PowerTool = 'power_tool';
    case HandTool = 'hand_tool';
    case Access = 'access';
    case Safety = 'safety';
    case Consumable = 'consumable';
    case It = 'it';
    case Other = 'other';

    public function label(): string
    {
        return __('enums.inventory_categories.'.$this->value);
    }
}
