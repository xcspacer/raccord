<?php

namespace App\Actions\Inventory;

use App\Enums\InventoryCondition;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryStatus;
use App\Inventory\InventoryException;
use App\Inventory\MovementRecorder;
use App\Models\InventoryItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Moves equipment from one holder to another without going through the stock. The expected current holder is
 * checked, so a transfer made on stale information is refused.
 */
class TransferItem
{
    use LocksItem;

    public function __construct(private MovementRecorder $movements) {}

    public function handle(User $actor, int $id, int $recipientId, int $expectedHolderId, ?int $quantity = null, ?string $condition = null, ?string $comment = null): InventoryItem
    {
        return DB::transaction(function () use ($actor, $id, $recipientId, $expectedHolderId, $quantity, $condition, $comment): InventoryItem {
            $item = $this->lock($id);
            $from = $item->holder;

            if ($from === null) {
                throw new InventoryException('not_held');
            }

            if ($from->id !== $expectedHolderId) {
                throw new InventoryException('holder_changed');
            }

            $recipient = $this->existingUser($recipientId);

            if ($recipient->id === $from->id) {
                throw new InventoryException('bad_recipient');
            }

            if (in_array($item->status, [InventoryStatus::Lost, InventoryStatus::Damaged, InventoryStatus::Retired, InventoryStatus::Repair], true)) {
                throw new InventoryException('not_transferable');
            }

            $line = $this->part($item, $quantity);
            $condition = InventoryCondition::tryFrom((string) $condition) ?? $line->condition;

            $line->update([
                'status' => InventoryStatus::Assigned, 'holder_id' => $recipient->id, 'condition' => $condition, 'pending_request' => null,
                'assigned_on' => CarbonImmutable::now(config('platform.timezone'))->toDateString(),
            ]);

            $this->movements->record($actor, $line, InventoryMovementType::Transfer, [
                'quantity' => $line->quantity, 'from' => $from, 'to' => $recipient, 'condition' => $condition->value,
                'comment' => $this->clean($comment, 300),
            ]);

            return $line;
        });
    }
}
