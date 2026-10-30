<?php

namespace App\Jobs;

use App\Ai\EquipmentLabelReader;
use App\Enums\EquipmentReadStatus;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reads the label of a freshly photographed equipment, after the person has been answered.
 */
class ReadEquipmentLabel
{
    use Queueable;

    public function __construct(public int $userId, public int $itemId) {}

    public function handle(EquipmentLabelReader $reader): void
    {
        $user = User::query()->find($this->userId);
        $item = InventoryItem::query()->find($this->itemId);

        if ($user !== null && $item !== null && $item->holder_id === $user->id && $item->read_status === EquipmentReadStatus::Reading) {
            $reader->read($user, $item);
        }
    }
}
