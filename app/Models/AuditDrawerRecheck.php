<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The recheck of the drawer plan of a cabinet: the positions changed against the plan, the PBO confirmed and,
 * once saved, the RECNOK workbook.
 *
 * @property int $id
 * @property int $audit_id
 * @property array<string, array{pb: string, cable: string, tube: int, fibre: int}>|null $changes
 * @property list<string>|null $confirmed
 * @property bool $has_unsaved_changes
 * @property int|null $recnok_file_id
 * @property string|null $recnok_name
 * @property Carbon|null $saved_at
 */
#[Fillable(['audit_id', 'changes', 'confirmed', 'has_unsaved_changes', 'recnok_file_id', 'recnok_name', 'saved_at', 'saved_by', 'saved_by_name'])]
class AuditDrawerRecheck extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['changes' => 'array', 'confirmed' => 'array', 'has_unsaved_changes' => 'boolean', 'saved_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function recnokFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'recnok_file_id');
    }
}
