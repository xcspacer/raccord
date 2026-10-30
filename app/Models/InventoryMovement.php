<?php

namespace App\Models;

use App\Enums\InventoryMovementType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of the append-only history of the inventory. Names are kept as they were when it happened.
 *
 * @property int $id
 * @property int $inventory_item_id
 * @property InventoryMovementType $type
 * @property int|null $user_id
 * @property string|null $user_name
 * @property string $label
 * @property int|null $quantity
 * @property int|null $from_user_id
 * @property string|null $from_name
 * @property int|null $to_user_id
 * @property string|null $to_name
 * @property int|null $holder_id
 * @property string|null $holder_name
 * @property string|null $condition
 * @property string|null $outcome
 * @property string|null $kind
 * @property string|null $comment
 * @property array<string, mixed>|null $details
 * @property Carbon $created_at
 */
#[Fillable(['inventory_item_id', 'type', 'user_id', 'user_name', 'label', 'quantity', 'from_user_id', 'from_name', 'to_user_id', 'to_name', 'holder_id', 'holder_name', 'condition', 'outcome', 'kind', 'comment', 'details', 'created_at'])]
class InventoryMovement extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['type' => InventoryMovementType::class, 'details' => 'array', 'quantity' => 'integer'];
    }

    /**
     * @return BelongsTo<InventoryItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}
