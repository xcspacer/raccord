<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A folder of the media library: a cabinet (no parent) or, inside it, a box or the cabinet itself (« PM »).
 *
 * @property int $id
 * @property int|null $parent_id
 * @property string $name
 * @property-read MediaFolder|null $parent
 */
#[Fillable(['parent_id', 'name'])]
class MediaFolder extends Model
{
    /** Name of the folder holding the photos of the cabinet itself. */
    public const string CABINET = 'PM';

    /**
     * @return BelongsTo<MediaFolder, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<MediaFolder, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<MediaFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(MediaFile::class);
    }

    public function isCabinet(): bool
    {
        return $this->parent_id === null;
    }
}
