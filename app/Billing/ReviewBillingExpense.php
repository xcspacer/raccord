<?php

namespace App\Billing;

use App\Enums\BillingExpenseStatus;
use App\Models\BillingExpense;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * An administrator approves or rejects a pending expense, once; a rejection always says why.
 */
class ReviewBillingExpense
{
    public function __construct(private BillingJournal $journal) {}

    public function approve(User $reviewer, BillingExpense $expense): void
    {
        $this->ensureReviewable($reviewer, $expense);

        $expense->update(['status' => BillingExpenseStatus::Approved, 'rejection_reason' => null, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now()]);
        $this->journal->record(BillingJournal::EXPENSE_APPROVED, $expense, $reviewer);
    }

    /**
     * @throws ValidationException
     */
    public function reject(User $reviewer, BillingExpense $expense, string $reason, string $field = 'reason'): void
    {
        $this->ensureReviewable($reviewer, $expense);
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([$field => __('billing.expenses.errors.reason')]);
        }

        $expense->update(['status' => BillingExpenseStatus::Rejected, 'rejection_reason' => mb_substr($reason, 0, 255), 'reviewed_by' => $reviewer->id, 'reviewed_at' => now()]);
        $this->journal->record(BillingJournal::EXPENSE_REJECTED, $expense, $reviewer);
    }

    private function ensureReviewable(User $reviewer, BillingExpense $expense): void
    {
        Gate::forUser($reviewer)->authorize('review', $expense);
        abort_unless($expense->isPending(), 409);
    }
}
