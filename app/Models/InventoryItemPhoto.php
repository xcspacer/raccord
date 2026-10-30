<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A photo of an inventory line: of the equipment itself (photo) or taken when it was given back or reported
 * (proof). A removed photo stays archived; its file is never deleted.
 *
 * @property int $id
 * @property int $inventory_item_id
 * @property int $stored_file_id
 * @property string $kind
 * @property int|null $added_by
 * @property Carbon|null $removed_at
 * @property Carbon $created_at
 */
#[Fillable(['inventory_item_id', 'stored_file_id', 'kind', 'added_by', 'removed_at', 'created_at'])]
class InventoryItemPhoto extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['removed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<InventoryItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'stored_file_id');
    }
}
