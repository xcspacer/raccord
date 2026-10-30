<?php

namespace App\Models;

use App\Enums\MediaPhase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A photo (or document) of a media library folder, in its « before » or « after » phase.
 *
 * @property int $id
 * @property int $media_folder_id
 * @property MediaPhase $phase
 * @property string $file_name
 * @property int $stored_file_id
 * @property int|null $uploaded_by
 * @property string|null $uploader_name
 * @property Carbon $uploaded_at
 * @property-read MediaFolder $folder
 * @property-read StoredFile $file
 */
#[Fillable(['media_folder_id', 'phase', 'file_name', 'stored_file_id', 'uploaded_by', 'uploader_name', 'uploaded_at'])]
class MediaFile extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['phase' => MediaPhase::class, 'uploaded_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<MediaFolder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(MediaFolder::class, 'media_folder_id');
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'stored_file_id');
    }

    public function isImage(): bool
    {
        return in_array(strtolower(pathinfo($this->file_name, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp'], true);
    }
}
