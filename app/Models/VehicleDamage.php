<?php

namespace App\Models;

use App\Enums\VehicleDamageType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $stored_file_id
 * @property VehicleDamageType|null $type
 * @property string|null $details
 * @property Carbon $occurred_on
 */
#[Fillable(['user_id', 'stored_file_id', 'type', 'details', 'occurred_on'])]
class VehicleDamage extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['type' => VehicleDamageType::class, 'occurred_on' => 'date'];
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'stored_file_id');
    }
}
