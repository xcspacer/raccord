<?php

namespace App\Actions\Inventory;

use App\Enums\InventoryCondition;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryStatus;
use App\Inventory\InventoryException;
use App\Inventory\MovementRecorder;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Takes equipment back from its holder: to the stock, to repair, damaged or out of service. Equipment reported
 * defective cannot go straight back to the stock: it goes to repair.
 */
class ReturnItem
{
    use LocksItem;

    public const array OUTCOMES = ['stock', 'repair', 'damaged', 'retired'];

    public function __construct(private MovementRecorder $movements) {}

    public function handle(User $actor, int $id, ?int $quantity = null, ?string $condition = null, string $outcome = 'stock', ?string $comment = null): InventoryItem
    {
        return DB::transaction(function () use ($actor, $id, $quantity, $condition, $outcome, $comment): InventoryItem {
            $item = $this->lock($id);
            $holder = $item->holder;

            if ($holder === null) {
                throw new InventoryException('not_held');
            }

            $line = $this->part($item, $quantity);
            $condition = InventoryCondition::tryFrom((string) $condition) ?? $line->condition;
            $status = InventoryStatus::from(in_array($outcome, self::OUTCOMES, true) ? $outcome : 'stock');

            if ($condition === InventoryCondition::Defective && $status === InventoryStatus::Stock) {
                $status = InventoryStatus::Repair;
            }

            $line->update(['status' => $status, 'holder_id' => null, 'condition' => $condition, 'returned_at' => now(), 'pending_request' => null]);

            $this->movements->record($actor, $line, InventoryMovementType::Return, [
                'quantity' => $line->quantity, 'from' => $holder, 'condition' => $condition->value, 'outcome' => $status->value,
                'comment' => $this->clean($comment, 300),
            ]);

            return $line;
        });
    }
}
