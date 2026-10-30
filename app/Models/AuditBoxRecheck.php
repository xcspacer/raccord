<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The recheck of a box against its plan (optical mode): the draft being edited on site, the cassettes declared
 * conform and, once saved, the RECNOK workbook.
 *
 * @property int $id
 * @property int $audit_box_id
 * @property array{changes?: array<int, array<string, string>>, cassette_numbers?: array<string, string>, manual_cables?: list<string>}|null $draft
 * @property list<string>|null $conforming_cassettes
 * @property bool $is_conform
 * @property bool $has_unsaved_changes
 * @property int|null $recnok_file_id
 * @property string|null $recnok_name
 * @property Carbon|null $saved_at
 * @property string|null $saved_by_name
 */
#[Fillable(['audit_box_id', 'draft', 'conforming_cassettes', 'is_conform', 'has_unsaved_changes', 'recnok_file_id', 'recnok_name', 'saved_at', 'saved_by', 'saved_by_name'])]
class AuditBoxRecheck extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['draft' => 'array', 'conforming_cassettes' => 'array', 'is_conform' => 'boolean', 'has_unsaved_changes' => 'boolean', 'saved_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<AuditBox, $this>
     */
    public function box(): BelongsTo
    {
        return $this->belongsTo(AuditBox::class, 'audit_box_id');
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function recnokFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'recnok_file_id');
    }
}
