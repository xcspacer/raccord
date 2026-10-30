<?php

namespace App\Models;

use App\Enums\BillingExpenseCategory;
use App\Enums\BillingExpenseStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * An expense a technician asks to be paid back, with its receipt photos; approved or rejected once.
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon $spent_on
 * @property BillingExpenseCategory $category
 * @property string|null $category_other
 * @property string $amount
 * @property string|null $mileage_km
 * @property string|null $comment
 * @property BillingExpenseStatus $status
 * @property string|null $rejection_reason
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'spent_on', 'category', 'category_other', 'amount', 'mileage_km', 'comment', 'status', 'rejection_reason', 'reviewed_by', 'reviewed_at'])]
class BillingExpense extends Model
{
    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['spent_on' => 'date', 'category' => BillingExpenseCategory::class, 'status' => BillingExpenseStatus::class, 'reviewed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<BillingExpensePhoto, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(BillingExpensePhoto::class);
    }

    public function isPending(): bool
    {
        return $this->status === BillingExpenseStatus::Pending;
    }
}
