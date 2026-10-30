<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shapes (circle, arrow, drawing, text) drawn by the design office over a photo; the original stays untouched and
 * annotations are internal (never shown to the client).
 *
 * @property int $id
 * @property int $audit_photo_id
 * @property int $author_id
 * @property list<array<string, mixed>> $shapes
 */
#[Fillable(['audit_photo_id', 'author_id', 'shapes'])]
class AuditPhotoAnnotation extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['shapes' => 'array'];
    }

    /**
     * @return BelongsTo<AuditPhoto, $this>
     */
    public function photo(): BelongsTo
    {
        return $this->belongsTo(AuditPhoto::class, 'audit_photo_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
