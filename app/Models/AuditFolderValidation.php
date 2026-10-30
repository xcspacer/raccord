<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A manager's validation of one photo folder of a box. It only stands while the folder holds as many photos as
 * when it was validated.
 *
 * @property int $audit_box_id
 * @property string $folder
 * @property int $photos_count
 * @property string|null $user_name
 * @property Carbon $validated_at
 */
#[Fillable(['audit_box_id', 'folder', 'user_id', 'user_name', 'photos_count', 'validated_at'])]
class AuditFolderValidation extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['validated_at' => 'datetime', 'photos_count' => 'integer'];
    }
}
