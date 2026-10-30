<?php

namespace App\Models;

use App\Enums\WorkSiteAttachmentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $work_site_id
 * @property WorkSiteAttachmentType $type
 * @property int $stored_file_id
 * @property string $original_name
 * @property int|null $uploaded_by
 */
#[Fillable(['work_site_id', 'type', 'stored_file_id', 'original_name', 'uploaded_by'])]
class WorkSiteAttachment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['type' => WorkSiteAttachmentType::class];
    }

    /**
     * @return BelongsTo<WorkSite, $this>
     */
    public function workSite(): BelongsTo
    {
        return $this->belongsTo(WorkSite::class);
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'stored_file_id');
    }
}
