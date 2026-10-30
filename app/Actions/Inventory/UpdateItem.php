<?php

namespace App\Actions\Inventory;

use App\Enums\EquipmentReadStatus;
use App\Enums\InventoryCondition;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryStatus;
use App\Inventory\InventoryException;
use App\Inventory\MovementRecorder;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Edits the descriptive fields of a line and keeps what changed in the history. A serial number makes the line a
 * serialized one for good; an empty designation is ignored.
 */
class UpdateItem
{
    use LocksItem;

    private const array TEXT_LIMITS = ['category' => 60, 'brand' => 60, 'model' => 60, 'serial' => 80, 'uncertainty' => 400, 'notes' => 600, 'vehicle' => 60];

    public function __construct(private MovementRecorder $movements) {}

    /**
     * @param  array<string, mixed>  $fields
     * @return bool Whether something changed.
     */
    public function handle(User $actor, int $id, array $fields): bool
    {
        return DB::transaction(function () use ($actor, $id, $fields): bool {
            $item = $this->lock($id);
            $changes = [];

            foreach (self::TEXT_LIMITS as $field => $limit) {
                if (array_key_exists($field, $fields)) {
                    $value = mb_substr(trim((string) $fields[$field]), 0, $limit);
                    $changes[$field] = $value === '' ? null : $value;
                }
            }

            $label = mb_substr(trim((string) ($fields['label'] ?? '')), 0, 120);

            if ($label !== '') {
                $changes['label'] = $label;
            }

            if (isset($fields['condition']) && InventoryCondition::tryFrom((string) $fields['condition']) !== null) {
                $changes['condition'] = $fields['condition'];
            }

            if (array_key_exists('needs_check', $fields)) {
                $changes['needs_check'] = (bool) $fields['needs_check'];
            }

            $serial = array_key_exists('serial', $changes) ? $changes['serial'] : $item->serial;
            $serialized = $item->is_serialized || $serial !== null;

            if (isset($fields['quantity']) && ! $serialized) {
                $changes['quantity'] = max(1, min(9999, (int) $fields['quantity']));
            }

            if ($serialized) {
                $changes['is_serialized'] = true;
                $changes['quantity'] = 1;
            }

            $normalized = $serial === null ? null : Str::lower(preg_replace('/[^A-Za-z0-9]/', '', $serial) ?? '');

            if ($normalized !== null && $normalized !== '' && $normalized !== $item->serial_normalized) {
                $other = InventoryItem::query()->where('serial_normalized', $normalized)->where('status', '!=', InventoryStatus::Retired->value)->whereKeyNot($item->id)->first();

                if ($other !== null) {
                    throw new InventoryException('serial_exists', ['reference' => $other->reference()]);
                }
            }

            $before = $item->only(array_keys($changes));
            $item->fill($changes);
            $dirty = array_keys($item->getDirty());

            if ($dirty === []) {
                return false;
            }

            $diff = [];

            foreach ($dirty as $field) {
                $diff[$field] = [$this->plain($before[$field] ?? null), $this->plain($item->getAttribute($field))];
            }

            if ($item->read_status !== null && $item->read_status !== EquipmentReadStatus::Reading) {
                $item->read_status = EquipmentReadStatus::Manual;
                $item->read_message = null;
            }

            $item->save();
            $this->movements->record($actor, $item, InventoryMovementType::Edit, ['details' => ['changes' => $diff]]);

            return true;
        });
    }

    private function plain(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }
}
