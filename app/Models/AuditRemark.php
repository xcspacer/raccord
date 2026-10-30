<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A message between the design office and the technician about a box, which the other side acknowledges.
 *
 * @property int $audit_box_id
 * @property string|null $client_ref
 * @property string $author_side
 * @property string $text
 * @property string|null $user_name
 * @property string|null $acknowledged_by_name
 * @property Carbon|null $acknowledged_at
 * @property Carbon $created_at
 */
#[Fillable(['client_ref', 'author_side', 'user_id', 'user_name', 'text', 'created_at'])]
class AuditRemark extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['acknowledged_at' => 'datetime'];
    }
}
