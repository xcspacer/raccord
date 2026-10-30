<?php

namespace App\Billing;

use App\Actions\Files\StoreUploadedFile;
use App\Enums\BillingExpenseCategory;
use App\Enums\BillingExpenseStatus;
use App\Models\BillingExpense;
use App\Models\BillingExpensePhoto;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a person's expense while it waits for review; receipts are stored or removed on save.
 *
 * @phpstan-import-type ExpenseInput from ExpenseRules
 */
class SaveBillingExpense
{
    public function __construct(private StoreUploadedFile $files, private BillingJournal $journal) {}

    /**
     * @param  ExpenseInput  $input
     * @param  list<UploadedFile>  $uploads
     * @param  list<int>  $removedPhotoIds
     */
    public function handle(User $owner, array $input, ?BillingExpense $expense, array $uploads = [], array $removedPhotoIds = []): BillingExpense
    {
        abort_if($expense !== null && ($expense->user_id !== $owner->id || ! $expense->isPending()), 403);

        $category = BillingExpenseCategory::from($input['category']);

        return DB::transaction(function () use ($owner, $input, $expense, $uploads, $removedPhotoIds, $category): BillingExpense {
            $attributes = [
                'spent_on' => $input['date'],
                'category' => $category,
                'category_other' => $category === BillingExpenseCategory::Other ? trim($input['categoryOther']) : null,
                'amount' => ExpenseRules::number($input['amount']),
                'mileage_km' => $category === BillingExpenseCategory::Fuel ? ExpenseRules::number($input['mileage']) : null,
                'comment' => trim($input['comment']) === '' ? null : trim($input['comment']),
            ];

            if ($expense === null) {
                $expense = BillingExpense::query()->create($attributes + ['user_id' => $owner->id, 'status' => BillingExpenseStatus::Pending]);
            } else {
                $expense->update($attributes);
            }

            foreach ($expense->photos()->with('file')->whereIn('id', $removedPhotoIds)->get() as $photo) {
                /** @var BillingExpensePhoto $photo */
                $stored = $photo->file;
                $photo->delete();
                $this->files->release($stored);
            }

            foreach ($uploads as $file) {
                $expense->photos()->create(['stored_file_id' => $this->files->handleImage($file)->id]);
            }

            $this->journal->record(BillingJournal::EXPENSE_SAVED, $expense, $owner, $this->journal->expense($expense));

            return $expense->refresh();
        });
    }
}
