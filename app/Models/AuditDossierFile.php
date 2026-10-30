<?php

namespace App\Models;

use App\Enums\AuditDossierFileRole;
use App\Enums\AuditDossierStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A file of a published dossier version at its path in the ZIP. The content is the stored file (photos are never
 * copied): the foreign key keeps it on the disk as long as the version exists, so a published version never loses
 * a file.
 *
 * @property int $id
 * @property int $audit_dossier_version_id
 * @property string $path
 * @property AuditDossierFileRole $role
 * @property int $stored_file_id
 * @property-read StoredFile $file
 */
#[Fillable(['audit_dossier_version_id', 'path', 'role', 'stored_file_id'])]
class AuditDossierFile extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['role' => AuditDossierFileRole::class];
    }

    protected static function booted(): void
    {
        $guard = function (self $file): void {
            if (AuditDossierVersion::query()->whereKey($file->audit_dossier_version_id)->where('status', AuditDossierStatus::Published)->exists()) {
                throw new LogicException('A published dossier version is immutable.');
            }
        };

        static::saving($guard);
        static::deleting($guard);
    }

    /**
     * @return BelongsTo<AuditDossierVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(AuditDossierVersion::class, 'audit_dossier_version_id');
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'stored_file_id');
    }
}
