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
 * A manager deals with an anomaly: the equipment is found or repaired and goes back to its holder or to the stock,
 * or it goes to repair or out of service. Going back in service sets the condition to « good » unless another is given.
 */
class ResolveAnomaly
{
    use LocksItem;

    public const array OUTCOMES = ['assigned', 'stock', 'repair', 'retired'];

    public function __construct(private MovementRecorder $movements) {}

    public function handle(User $actor, int $id, string $outcome, ?string $comment = null, ?string $condition = null): InventoryItem
    {
        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw new InventoryException('bad_outcome');
        }

        return DB::transaction(function () use ($actor, $id, $outcome, $comment, $condition): InventoryItem {
            $item = $this->lock($id);
            $holder = $item->holder;

            if ($outcome === 'assigned' && $holder === null) {
                throw new InventoryException('no_holder');
            }

            $backInService = in_array($outcome, ['assigned', 'stock'], true);

            $item->update([
                'status' => InventoryStatus::from($outcome),
                'holder_id' => $outcome === 'stock' ? null : $item->holder_id,
                'condition' => $backInService ? (InventoryCondition::tryFrom((string) $condition) ?? InventoryCondition::Good) : $item->condition,
                'anomaly' => null,
                'pending_request' => null,
            ]);

            $this->movements->record($actor, $item, InventoryMovementType::Resolve, [
                'quantity' => $item->quantity, 'holder' => $holder, 'outcome' => $outcome,
                'comment' => $this->clean($comment, 300),
            ]);

            return $item;
        });
    }
}
