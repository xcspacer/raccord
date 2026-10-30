<?php

namespace App\Livewire\Billing;

use App\Actions\Files\UploadRules;
use App\Billing\BillingJournal;
use App\Billing\ExpenseRules;
use App\Billing\SaveBillingExpense;
use App\Enums\BillingExpenseCategory;
use App\Models\BillingExpense;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * « Remboursement de frais » / « Mes frais »: the person declares expenses with a receipt photo and changes or
 * deletes them while they wait for review.
 *
 * @phpstan-import-type ExpenseInput from ExpenseRules
 */
class MyExpenses extends Component
{
    use WithFileUploads;

    #[Locked]
    public ?int $editingId = null;

    public string $date = '';

    public string $category = 'fuel';

    public string $categoryOther = '';

    public string $amount = '';

    public string $mileage = '';

    public string $comment = '';

    /** @var array<string, list<TemporaryUploadedFile>> photos just picked, merged into */
    public array $incoming = ['receipts' => []];

    /** @var list<TemporaryUploadedFile> */
    public array $newPhotos = [];

    /** @var list<int> */
    public array $removedPhotoIds = [];

    public string $error = '';

    #[Locked]
    public ?int $detailId = null;

    public function mount(): void
    {
        $this->authorize('create', BillingExpense::class);
        $this->resetForm();
    }

    /**
     * @param  mixed  $value
     */
    public function updatedIncoming($value, string $key): void
    {
        $this->validate(['incoming.receipts.*' => UploadRules::photo(15360)]);
        $this->newPhotos = [...$this->newPhotos, ...$this->incoming['receipts']];
        $this->incoming['receipts'] = [];
    }

    public function removeNewPhoto(int $index): void
    {
        array_splice($this->newPhotos, $index, 1);
    }

    public function removePhoto(int $id): void
    {
        $this->removedPhotoIds[] = $id;
    }

    public function save(ExpenseRules $rules, SaveBillingExpense $saver): void
    {
        $this->authorize('create', BillingExpense::class);
        $expense = $this->editingId === null ? null : $this->ownPending($this->editingId);
        $kept = $expense === null ? 0 : $expense->photos()->whereNotIn('id', $this->removedPhotoIds)->count();
        $input = $this->input($kept + count($this->newPhotos));
        $this->error = $rules->firstError($input) ?? '';

        if ($this->error !== '') {
            return;
        }

        $saver->handle($this->user(), $input, $expense, $this->newPhotos, $this->removedPhotoIds);
        $this->editingId = null;
        $this->resetForm();
        $this->dispatch('toast', message: __('billing.expenses.saved'));
    }

    public function edit(int $id): void
    {
        $expense = $this->ownPending($id);

        $this->editingId = $expense->id;
        $this->date = $expense->spent_on->toDateString();
        $this->category = $expense->category->value;
        $this->categoryOther = (string) $expense->category_other;
        $this->amount = (string) (float) $expense->amount;
        $this->mileage = $expense->mileage_km === null ? '' : (string) (float) $expense->mileage_km;
        $this->comment = (string) $expense->comment;
        $this->newPhotos = [];
        $this->removedPhotoIds = [];
        $this->error = '';
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->resetForm();
    }

    public function delete(int $id, BillingJournal $journal): void
    {
        $expense = $this->ownPending($id, 'delete');
        $journal->record(BillingJournal::EXPENSE_DELETED, $expense, $this->user(), $journal->expense($expense));
        $expense->delete();

        if ($this->editingId === $id) {
            $this->cancelEdit();
        }
    }

    public function showDetail(int $id): void
    {
        $this->detailId = BillingExpense::query()->where('user_id', $this->user()->id)->findOrFail($id)->id;
    }

    public function closeDetail(): void
    {
        $this->detailId = null;
    }

    public function render(): View
    {
        $expenses = BillingExpense::query()->with(['photos', 'user:id,name'])->where('user_id', $this->user()->id)
            ->orderByDesc('spent_on')->orderByDesc('created_at')->get();

        return view('livewire.billing.my-expenses', [
            'expenses' => $expenses,
            'categories' => BillingExpenseCategory::cases(),
            'existingPhotos' => $this->editingId === null ? collect() : $this->ownPending($this->editingId)->photos()->whereNotIn('id', $this->removedPhotoIds)->get(),
            'detail' => $this->detailId === null ? null : $expenses->firstWhere('id', $this->detailId),
        ]);
    }

    /**
     * @return ExpenseInput
     */
    private function input(int $photos): array
    {
        return ['date' => $this->date, 'category' => $this->category, 'categoryOther' => $this->categoryOther, 'amount' => $this->amount, 'mileage' => $this->mileage, 'comment' => $this->comment, 'photos' => $photos];
    }

    /**
     * The person's own expense that has not been reviewed yet (BillingExpensePolicy).
     */
    private function ownPending(int $id, string $ability = 'update'): BillingExpense
    {
        $expense = BillingExpense::query()->findOrFail($id);
        $this->authorize($ability, $expense);

        return $expense;
    }

    private function resetForm(): void
    {
        $this->reset('category', 'categoryOther', 'amount', 'mileage', 'comment', 'incoming', 'newPhotos', 'removedPhotoIds', 'error');
        $this->date = CarbonImmutable::now(config('platform.timezone'))->toDateString();
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
