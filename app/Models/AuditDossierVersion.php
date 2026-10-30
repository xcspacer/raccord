<?php

namespace App\Models;

use App\Enums\AuditDossierKind;
use App\Enums\AuditDossierStatus;
use Database\Factories\AuditDossierVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A version of the official delivery dossier of a closed audit, generated in the background and published at once
 * (never partially). One version in progress per audit (guard column `in_progress_audit_id`); a published version
 * never changes.
 *
 * @property int $id
 * @property int $audit_id
 * @property int $version_number
 * @property AuditDossierKind $kind
 * @property AuditDossierStatus $status
 * @property string|null $reason
 * @property string|null $locale Language the dossier is written in (the client's, kept from one version to the next).
 * @property int|null $requested_by
 * @property int|null $authorized_by
 * @property int $attempts
 * @property Carbon|null $started_at
 * @property string|null $error
 * @property list<array{code: string, ok: bool, blocking: bool, detail: string}>|null $checks
 * @property array{previous: int, added: list<string>, replaced: list<string>, removed: list<string>}|null $changes
 * @property Carbon|null $published_at
 * @property Carbon $created_at
 * @property-read Collection<int, AuditDossierFile> $files
 */
#[Fillable(['audit_id', 'version_number', 'kind', 'status', 'reason', 'locale', 'requested_by', 'authorized_by', 'attempts', 'started_at', 'error', 'checks', 'changes', 'published_at'])]
class AuditDossierVersion extends Model
{
    /** @use HasFactory<AuditDossierVersionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['kind' => AuditDossierKind::class, 'status' => AuditDossierStatus::class, 'checks' => 'array', 'changes' => 'array', 'started_at' => 'datetime', 'published_at' => 'datetime', 'attempts' => 'integer', 'version_number' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $version): void {
            if ($version->exists && $version->getOriginal('status') === AuditDossierStatus::Published) {
                throw new LogicException('A published dossier version is immutable.');
            }

            $version->setAttribute('in_progress_audit_id', $version->status->isInProgress() ? $version->audit_id : null);
        });
        static::deleting(function (self $version): void {
            if ($version->status === AuditDossierStatus::Published) {
                throw new LogicException('A published dossier version is immutable.');
            }
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
     * Every file of the version, at its path in the ZIP (written once, when the version is published).
     *
     * @return HasMany<AuditDossierFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(AuditDossierFile::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function authorizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isPublished(): bool
    {
        return $this->status === AuditDossierStatus::Published;
    }
}
