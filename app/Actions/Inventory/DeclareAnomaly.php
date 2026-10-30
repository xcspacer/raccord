<?php

namespace App\Actions\Inventory;

use App\Enums\InventoryAnomalyKind;
use App\Enums\InventoryCondition;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryStatus;
use App\Inventory\InventoryException;
use App\Inventory\MovementRecorder;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Declares equipment lost, damaged or missing. The holder keeps it on their list until a manager deals with it;
 * missing equipment counts as lost, the kind being kept with the anomaly.
 */
class DeclareAnomaly
{
    use LocksItem;

    public function __construct(private MovementRecorder $movements) {}

    public function handle(User $actor, int $id, InventoryAnomalyKind $kind, string $comment, ?int $quantity = null): InventoryItem
    {
        $comment = mb_substr(trim($comment), 0, 600);

        if ($comment === '') {
            throw new InventoryException('comment_required');
        }

        return DB::transaction(function () use ($actor, $id, $kind, $comment, $quantity): InventoryItem {
            $item = $this->lock($id);

            if ($item->holder_id === null || in_array($item->status, [InventoryStatus::Lost, InventoryStatus::Damaged, InventoryStatus::Retired], true)) {
                throw new InventoryException('state_changed');
            }

            $line = $this->part($item, $quantity);
            $damaged = $kind === InventoryAnomalyKind::Damaged;

            $line->update([
                'status' => $damaged ? InventoryStatus::Damaged : InventoryStatus::Lost,
                'condition' => $damaged ? InventoryCondition::Defective : $line->condition,
                'anomaly' => ['kind' => $kind->value, 'at' => now()->toIso8601String(), 'by' => $actor->id, 'by_name' => $actor->name, 'comment' => $comment],
                'pending_request' => null,
            ]);

            $this->movements->record($actor, $line, InventoryMovementType::Anomaly, ['quantity' => $line->quantity, 'holder' => $line->holder, 'kind' => $kind->value, 'comment' => $comment]);

            return $line;
        });
    }
}
