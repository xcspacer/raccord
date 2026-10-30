<?php

namespace App\Actions\Inventory;

use App\Enums\InventoryMovementType;
use App\Enums\InventoryRequestKind;
use App\Inventory\MovementRecorder;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The request a holder makes to the manager (give the equipment back, replace it) and its closing. A new request
 * replaces the pending one; neither changes the state of the equipment.
 */
class ManageRequest
{
    use LocksItem;

    public function __construct(private MovementRecorder $movements) {}

    public function open(User $actor, int $id, InventoryRequestKind $kind, ?string $comment = null): InventoryItem
    {
        return DB::transaction(function () use ($actor, $id, $kind, $comment): InventoryItem {
            $item = $this->lock($id);
            $comment = $this->clean($comment, 400);

            $item->update(['pending_request' => ['kind' => $kind->value, 'at' => now()->toIso8601String(), 'by' => $actor->id, 'by_name' => $actor->name, 'comment' => $comment]]);
            $this->movements->record($actor, $item, InventoryMovementType::Request, ['holder' => $item->holder, 'kind' => $kind->value, 'comment' => $comment]);

            return $item;
        });
    }

    public function close(User $actor, int $id, ?string $comment = null): InventoryItem
    {
        return DB::transaction(function () use ($actor, $id, $comment): InventoryItem {
            $item = $this->lock($id);

            $item->update(['pending_request' => null]);
            $this->movements->record($actor, $item, InventoryMovementType::RequestClose, ['holder' => $item->holder, 'comment' => $this->clean($comment, 400)]);

            return $item;
        });
    }
}
