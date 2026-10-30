<?php

namespace App\Models;

use App\Enums\UserDocumentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Personal document of a user (BTP card, driving licence) — private to the user and managers.
 *
 * @property int $id
 * @property int $user_id
 * @property UserDocumentType $type
 * @property int $stored_file_id
 * @property string $original_name
 * @property Carbon|null $expires_on
 */
#[Fillable(['user_id', 'type', 'stored_file_id', 'original_name', 'expires_on'])]
class UserDocument extends Model
{
    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => UserDocumentType::class,
            'expires_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'stored_file_id');
    }
}
