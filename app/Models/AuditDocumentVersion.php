<?php

namespace App\Models;

use App\Enums\AuditDocumentKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A version of a document of the audit (synoptic, map file) published by the design office or a supervisor. One
 * current version per kind (guard column `current_key`); earlier ones stay archived.
 *
 * @property int $id
 * @property int $audit_id
 * @property AuditDocumentKind $kind
 * @property int $version_number
 * @property int $stored_file_id
 * @property string $original_name
 * @property bool $is_current
 * @property array<string, mixed>|null $summary
 * @property int|null $published_by
 * @property Carbon $published_at
 */
#[Fillable(['audit_id', 'kind', 'version_number', 'stored_file_id', 'original_name', 'is_current', 'summary', 'published_by', 'published_at'])]
class AuditDocumentVersion extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['kind' => AuditDocumentKind::class, 'is_current' => 'boolean', 'summary' => 'array', 'published_at' => 'datetime', 'version_number' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $version): void {
            $version->setAttribute('current_key', $version->is_current ? $version->audit_id.':'.$version->kind->value : null);
        });
    }

    /**
     * @return BelongsTo<Audit, $this>
     */
    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'stored_file_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /**
     * Corrections of the synoptic this version includes.
     *
     * @return HasMany<AuditSynopticCorrection, $this>
     */
    public function synopticCorrections(): HasMany
    {
        return $this->hasMany(AuditSynopticCorrection::class);
    }
}
