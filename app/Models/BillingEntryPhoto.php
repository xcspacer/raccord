<?php

namespace App\Models;

use App\Enums\BillingPhotoCategory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A photo of an entry; works photos are taken before or after the intervention.
 *
 * @property int $id
 * @property int $billing_entry_id
 * @property BillingPhotoCategory|null $category
 * @property int $stored_file_id
 * @property int|null $uploaded_by
 * @property Carbon $created_at
 * @property-read StoredFile $file
 */
#[Fillable(['category', 'stored_file_id', 'uploaded_by'])]
class BillingEntryPhoto extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['category' => BillingPhotoCategory::class];
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'stored_file_id');
    }

    /**
     * @return BelongsTo<BillingEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(BillingEntry::class, 'billing_entry_id');
    }
}
