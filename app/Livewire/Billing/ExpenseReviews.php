<?php

namespace App\Livewire\Billing;

use App\Billing\ReviewBillingExpense;
use App\Enums\BillingExpenseStatus;
use App\Models\BillingExpense;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * « Remboursements »: the team's expenses by month, person and status, or every one still waiting; administrators
 * approve or reject them, supervisors only follow.
 */
class ExpenseReviews extends Component
{
    public string $month = '';

    public string $technician = '';

    public string $status = '';

    public bool $pendingOnly = false;

    #[Locked]
    public ?int $rejectingId = null;

    public string $reason = '';

    #[Locked]
    public ?int $detailId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', BillingExpense::class);

        $this->month = CarbonImmutable::now(config('platform.timezone'))->format('Y-m');
    }

    /**
     * Changing a filter leaves the « every pending expense » view.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['month', 'technician', 'status'], true)) {
            $this->pendingOnly = false;
        }
    }

    public function showPending(bool $only): void
    {
        $this->pendingOnly = $only;
    }

    public function approve(int $id, ReviewBillingExpense $review): void
    {
        $review->approve($this->user(), BillingExpense::query()->findOrFail($id));
        $this->cancelReject();
    }

    public function startReject(int $id): void
    {
        $expense = BillingExpense::query()->findOrFail($id);
        $this->authorize('review', $expense);
        $this->rejectingId = $expense->id;
        $this->reset('reason');
        $this->resetValidation();
    }

    public function confirmReject(ReviewBillingExpense $review): void
    {
        $review->reject($this->user(), BillingExpense::query()->findOrFail($this->rejectingId), $this->reason);
        $this->cancelReject();
    }

    public function cancelReject(): void
    {
        $this->reset('rejectingId', 'reason');
        $this->resetValidation();
    }

    public function showDetail(int $id): void
    {
        $this->authorize('viewAny', BillingExpense::class);
        $this->detailId = BillingExpense::query()->findOrFail($id)->id;
    }

    public function closeDetail(): void
    {
        $this->detailId = null;
    }

    public function render(): View
    {
        $user = $this->user();
        $all = BillingExpense::query()->with(['photos', 'user:id,name'])->orderByDesc('spent_on')->orderByDesc('created_at')->get();
        $pending = $all->where('status', BillingExpenseStatus::Pending)->values();

        if ($this->pendingOnly && $pending->isEmpty()) {
            $this->pendingOnly = false;
        }

        $filtered = $all
            ->filter(fn (BillingExpense $expense) => $this->technician === '' || (string) $expense->user_id === $this->technician)
            ->filter(fn (BillingExpense $expense) => $this->status === '' || $expense->status->value === $this->status)
            ->filter(fn (BillingExpense $expense) => $this->month === '' || $expense->spent_on->format('Y-m') === $this->month)
            ->values();

        $monthExpenses = $all->filter(fn (BillingExpense $expense) => $this->month !== '' && $expense->spent_on->format('Y-m') === $this->month);
        $personExpenses = $monthExpenses->filter(fn (BillingExpense $expense) => (string) $expense->user_id === $this->technician);

        return view('livewire.billing.expense-reviews', [
            'expenses' => $this->pendingOnly ? $pending : $filtered,
            'pendingCount' => $pending->count(),
            'approvedTotal' => (float) $filtered->where('status', BillingExpenseStatus::Approved)->sum(fn (BillingExpense $expense) => (float) $expense->amount),
            'technicians' => $this->technicians($all),
            'canReview' => $user->can('review', BillingExpense::class),
            'detail' => $this->detailId === null ? null : $all->firstWhere('id', $this->detailId),
            'exportable' => $this->technician === '' ? $monthExpenses->isNotEmpty() : $personExpenses->isNotEmpty(),
            'exportPending' => $personExpenses->contains('status', BillingExpenseStatus::Pending),
            'exportApproved' => $personExpenses->contains('status', BillingExpenseStatus::Approved),
        ]);
    }

    /**
     * People who declared expenses, by name.
     *
     * @param  Collection<int, BillingExpense>  $expenses
     * @return array<int, string>
     */
    private function technicians(Collection $expenses): array
    {
        return $expenses->mapWithKeys(fn (BillingExpense $expense) => [$expense->user_id => $expense->user->name])->sort()->all();
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
