<?php

namespace App\Models;

use App\Enums\AuditFibreType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cable of a box, recorded in the optical mode.
 *
 * @property int $id
 * @property int $audit_box_id
 * @property string $code
 * @property bool $has_no_label
 * @property string|null $capacity
 * @property AuditFibreType|null $fibre_type
 * @property string|null $note
 */
#[Fillable(['code', 'has_no_label', 'capacity', 'fibre_type', 'note'])]
class AuditCable extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['has_no_label' => 'boolean', 'fibre_type' => AuditFibreType::class];
    }

    /**
     * @return BelongsTo<AuditBox, $this>
     */
    public function box(): BelongsTo
    {
        return $this->belongsTo(AuditBox::class, 'audit_box_id');
    }
}
