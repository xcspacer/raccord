<?php

namespace App\Inventory;

use App\Enums\InventoryMovementType;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\User;

/**
 * Appends a line to the inventory history, with the names of the people as they are now.
 */
class MovementRecorder
{
    /**
     * @param  array{quantity?: int|null, from?: User|null, to?: User|null, holder?: User|null, condition?: string|null, outcome?: string|null, kind?: string|null, comment?: string|null, details?: array<string, mixed>|null}  $extra
     */
    public function record(User $actor, InventoryItem $item, InventoryMovementType $type, array $extra = []): InventoryMovement
    {
        return InventoryMovement::query()->create([
            'inventory_item_id' => $item->id,
            'type' => $type,
            'user_id' => $actor->id,
            'user_name' => $actor->name,
            'label' => $item->label,
            'quantity' => $extra['quantity'] ?? null,
            'from_user_id' => ($extra['from'] ?? null)?->id,
            'from_name' => ($extra['from'] ?? null)?->name,
            'to_user_id' => ($extra['to'] ?? null)?->id,
            'to_name' => ($extra['to'] ?? null)?->name,
            'holder_id' => ($extra['holder'] ?? null)?->id,
            'holder_name' => ($extra['holder'] ?? null)?->name,
            'condition' => $extra['condition'] ?? null,
            'outcome' => $extra['outcome'] ?? null,
            'kind' => $extra['kind'] ?? null,
            'comment' => $extra['comment'] ?? null,
            'details' => $extra['details'] ?? null,
            'created_at' => now(),
        ]);
    }
}
