<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A receipt photo of an expense.
 *
 * @property int $id
 * @property int $billing_expense_id
 * @property int $stored_file_id
 * @property-read StoredFile $file
 */
#[Fillable(['stored_file_id'])]
class BillingExpensePhoto extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'stored_file_id');
    }

    /**
     * @return BelongsTo<BillingExpense, $this>
     */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(BillingExpense::class, 'billing_expense_id');
    }
}
