<?php

namespace App\Models;

use App\Enums\ExpenseLineStatus;
use App\Enums\ExpenseNature;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A line of the expense report being drafted by a person (all amounts are tax-inclusive, in cents).
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon|null $spent_on
 * @property ExpenseNature $nature
 * @property string|null $comment
 * @property int $amount_cents
 * @property string|null $merchant
 * @property int|null $stored_file_id
 * @property string|null $photo_hash
 * @property ExpenseLineStatus $status
 * @property string|null $message
 * @property bool $duplicate_confirmed
 */
#[Fillable(['user_id', 'spent_on', 'nature', 'comment', 'amount_cents', 'merchant', 'stored_file_id', 'photo_hash', 'status', 'message', 'duplicate_confirmed'])]
class ExpenseLine extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['amount_cents' => 0, 'duplicate_confirmed' => false];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['spent_on' => 'date', 'nature' => ExpenseNature::class, 'status' => ExpenseLineStatus::class, 'amount_cents' => 'integer', 'duplicate_confirmed' => 'boolean'];
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
