<?php

namespace App\Models;

use App\Enums\AuditGeoMissingReason;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A photo of a box or of a control point, with where it was taken, when the phone took it (`captured_at`, kept even
 * when it was sent later from offline) and when the server received it (`received_at`). `file_name` is the name it
 * was already known by before the platform (imported photos); new photos are named from the reception time.
 *
 * @property int $id
 * @property int $audit_id
 * @property int|null $audit_box_id
 * @property int|null $audit_control_point_id
 * @property string $folder
 * @property string $subfolder
 * @property int $stored_file_id
 * @property string|null $file_name
 * @property Carbon $captured_at
 * @property string|null $latitude
 * @property string|null $longitude
 * @property int|null $user_id
 * @property string|null $user_name
 * @property bool $is_imported
 * @property bool $is_geo_missing
 * @property AuditGeoMissingReason|null $geo_missing_reason
 * @property Carbon $received_at
 */
#[Fillable(['audit_id', 'audit_box_id', 'audit_control_point_id', 'folder', 'subfolder', 'stored_file_id', 'file_name', 'captured_at', 'latitude', 'longitude', 'user_id', 'user_name', 'is_imported', 'is_geo_missing', 'geo_missing_reason', 'received_at'])]
class AuditPhoto extends Model
{
    public const CREATED_AT = 'received_at';

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['captured_at' => 'datetime', 'received_at' => 'datetime', 'is_imported' => 'boolean', 'is_geo_missing' => 'boolean', 'geo_missing_reason' => AuditGeoMissingReason::class];
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'stored_file_id');
    }

    /**
     * @return BelongsTo<Audit, $this>
     */
    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }

    /**
     * @return BelongsTo<AuditBox, $this>
     */
    public function box(): BelongsTo
    {
        return $this->belongsTo(AuditBox::class, 'audit_box_id');
    }
}
