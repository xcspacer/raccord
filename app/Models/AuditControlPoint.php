<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A control point of the cabinet audit (the cabinet itself, a chamber, a clean-up…), photographed like a box.
 *
 * @property int $id
 * @property int $audit_id
 * @property string $name
 * @property string|null $kind
 * @property list<string>|null $subfolders
 * @property bool $is_custom
 * @property string|null $note
 * @property list<array{id: string, label: string, qty: int}>|null $report_items
 * @property Carbon|null $report_at
 * @property string|null $report_by_name
 * @property int|null $pdf_file_id
 * @property string|null $pdf_name
 * @property Carbon|null $pdf_generated_at
 */
#[Fillable(['audit_id', 'name', 'kind', 'subfolders', 'is_custom'])]
class AuditControlPoint extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['subfolders' => 'array', 'is_custom' => 'boolean', 'report_items' => 'array', 'pdf_generated_at' => 'datetime', 'report_at' => 'datetime'];
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
    public function pdfFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'pdf_file_id');
    }

    /**
     * @return HasMany<AuditPhoto, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(AuditPhoto::class)->orderBy('captured_at')->orderBy('id');
    }
}
