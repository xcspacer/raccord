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
 * Hands equipment from the stock to a person, all of the line or a number of units.
 */
class AssignItem
{
    use LocksItem;

    public function __construct(private MovementRecorder $movements) {}

    public function handle(User $actor, int $id, int $recipientId, ?int $quantity = null, ?string $condition = null, ?string $date = null): InventoryItem
    {
        return DB::transaction(function () use ($actor, $id, $recipientId, $quantity, $condition, $date): InventoryItem {
            $item = $this->lock($id);

            if ($item->status !== InventoryStatus::Stock || $item->holder_id !== null) {
                throw new InventoryException('not_available');
            }

            $recipient = $this->existingUser($recipientId);
            $line = $this->part($item, $quantity);
            $condition = InventoryCondition::tryFrom((string) $condition);

            $line->update([
                'status' => InventoryStatus::Assigned, 'holder_id' => $recipient->id,
                'assigned_on' => $date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : CarbonImmutable::now(config('platform.timezone'))->toDateString(),
                'condition' => $condition ?? $line->condition,
            ]);

            $this->movements->record($actor, $line, InventoryMovementType::Assign, ['quantity' => $line->quantity, 'to' => $recipient, 'condition' => $line->condition->value, 'details' => $line->split_from_id === $item->id ? ['split_from' => $item->reference()] : null]);

            return $line;
        });
    }
}
