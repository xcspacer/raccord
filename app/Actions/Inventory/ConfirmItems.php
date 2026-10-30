<?php

namespace App\Actions\Inventory;

use App\Enums\InventoryMovementType;
use App\Enums\InventoryStatus;
use App\Inventory\MovementRecorder;
use App\Models\InventoryItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Turns equipment noted on a person into an official handover. Lines that are not waiting for confirmation are
 * left alone.
 */
class ConfirmItems
{
    use LocksItem;

    public function __construct(private MovementRecorder $movements) {}

    /**
     * @param  list<int>  $ids
     * @return int Number of lines confirmed.
     */
    public function handle(User $actor, array $ids): int
    {
        return DB::transaction(function () use ($actor, $ids): int {
            $items = InventoryItem::query()->whereIn('id', $ids)->where('status', InventoryStatus::ToConfirm->value)->lockForUpdate()->with('holder')->get();

            foreach ($items as $item) {
                $item->update([
                    'status' => InventoryStatus::Assigned, 'confirmed_by' => $actor->id, 'confirmed_at' => now(),
                    'assigned_on' => $item->assigned_on ?? CarbonImmutable::now(config('platform.timezone'))->toDateString(),
                ]);

                $this->movements->record($actor, $item, InventoryMovementType::Confirm, ['quantity' => $item->quantity, 'holder' => $item->holder]);
            }

            return $items->count();
        });
    }
}
