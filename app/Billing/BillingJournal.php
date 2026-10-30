<?php

namespace App\Billing;

use App\Models\BillingEntry;
use App\Models\BillingEntryLine;
use App\Models\BillingExpense;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only trail of every billing write: who did what and when, with what the record looked like. Price changes
 * keep their own history (`billing_rate_changes`).
 */
class BillingJournal
{
    public const string LOG = 'billing';

    public const string ENTRY_SAVED = 'entry_saved';

    public const string ENTRY_DELETED = 'entry_deleted';

    public const string EXPENSE_SAVED = 'expense_saved';

    public const string EXPENSE_DELETED = 'expense_deleted';

    public const string EXPENSE_APPROVED = 'expense_approved';

    public const string EXPENSE_REJECTED = 'expense_rejected';

    public const string OPERATOR_ADDED = 'operator_added';

    public const string OPERATOR_REMOVED = 'operator_removed';

    public const string CLIENT_SAVED = 'client_saved';

    public const string TYPE_ADDED = 'type_added';

    public const string TYPE_REMOVED = 'type_removed';

    public const string ARTICLE_ADDED = 'article_added';

    public const string ARTICLE_TOGGLED = 'article_toggled';

    public const string DEFAULT_CLIENT_SET = 'default_client_set';

    /**
     * @param  array<string, mixed>  $details
     */
    public function record(string $event, Model $subject, ?User $by, array $details = []): void
    {
        activity(self::LOG)
            ->performedOn($subject)
            ->causedBy($by)
            ->event($event)
            ->withProperties(['record' => $details ?: $subject->attributesToArray()])
            ->log($event);
    }

    /**
     * An entry with its partners and article lines.
     *
     * @return array<string, mixed>
     */
    public function entry(BillingEntry $entry): array
    {
        return $entry->attributesToArray() + [
            'partners' => $entry->partners()->pluck('users.id')->all(),
            'lines' => $entry->lines()->get()->map(fn (BillingEntryLine $line) => ['article' => $line->billing_article_id, 'quantity' => (float) $line->quantity])->all(),
            'photos' => $entry->photos()->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function expense(BillingExpense $expense): array
    {
        return $expense->attributesToArray() + ['photos' => $expense->photos()->count()];
    }
}
