<?php

namespace App\Actions\Inventory;

use App\Enums\InventoryMovementType;
use App\Inventory\InventoryException;
use App\Inventory\MovementRecorder;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Deletes (soft) and restores equipment lines. A restore is refused when the serial number has been registered
 * again in the meantime.
 */
class DeleteItem
{
    use LocksItem;

    public function __construct(private MovementRecorder $movements) {}

    public function delete(User $actor, int $id, ?string $comment = null): void
    {
        DB::transaction(function () use ($actor, $id, $comment): void {
            $item = $this->lock($id);
            $comment = $this->clean($comment, 300);

            $item->update(['deleted_by' => $actor->id, 'deleted_comment' => $comment, 'pending_request' => null]);
            $item->delete();
            $this->movements->record($actor, $item, InventoryMovementType::Delete, ['holder' => $item->holder, 'comment' => $comment]);
        });
    }

    public function restore(User $actor, int $id): void
    {
        DB::transaction(function () use ($actor, $id): void {
            $item = $this->lock($id, true);

            if (! $item->trashed()) {
                return;
            }

            if ($item->serial_normalized !== null) {
                $other = InventoryItem::query()->where('serial_normalized', $item->serial_normalized)->where('status', '!=', 'retired')->whereKeyNot($item->id)->first();

                if ($other !== null) {
                    throw new InventoryException('serial_exists', ['reference' => $other->reference()]);
                }
            }

            $item->restore();
            $item->update(['deleted_by' => null, 'deleted_comment' => null]);
            $this->movements->record($actor, $item, InventoryMovementType::Restore, ['holder' => $item->holder]);
        });
    }
}
