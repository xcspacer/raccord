<?php

namespace App\Enums;

/**
 * Things the platform asks the AI to do. Each has its own daily and hourly limits.
 */
enum AiFeature: string
{
    case ReadReceipt = 'receipt_read';
    case ReadEquipmentLabel = 'equip_read';
    case CompareVehiclePhotos = 'vehicle_compare';
    case IdentifyInventory = 'inventory_identify';
    case DraftOccurrenceText = 'occurrence_draft';

    /**
     * @return array{per_user: int, global: int, per_user_hour: int}
     */
    public function limits(): array
    {
        return config('services.anthropic.quotas.'.$this->value);
    }
}
