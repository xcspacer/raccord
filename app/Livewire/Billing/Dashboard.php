<?php

namespace App\Livewire\Billing;

use App\Billing\BillingAccess;
use App\Billing\BillingCalculator;
use App\Billing\BillingChecks;
use App\Billing\BillingJournal;
use App\Billing\BillingPeriod;
use App\Enums\BillingEntryType;
use App\Enums\BillingExpenseStatus;
use App\Models\BillingClient;
use App\Models\BillingEntry;
use App\Models\BillingExpense;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * « Tableau de bord responsable »: the team's entries by month or day, technician, type and client, with their
 * inconsistencies, the people named but not declared yet, and (administrators only) amounts and expenses.
 */
class Dashboard extends Component
{
    public string $month = '';

    public string $day = '';

    public string $technician = '';

    public string $type = '';

    public string $client = '';

    public bool $duplicatesOnly = false;

    public bool $crewOnly = false;

    public bool $contentOnly = false;

    public string $technicianSearch = '';

    public string $technicianSort = 'name';

    /** Administrator panel open below the counters: « people », « rates » or « clients ». */
    public string $panel = '';

    #[Locked]
    public ?int $ticketEditId = null;

    public string $ticketValue = '';

    public string $ticketError = '';

    #[Locked]
    public ?int $editEntryId = null;

    #[Locked]
    public ?int $detailId = null;

    public function mount(): void
    {
        $this->authorize('viewAll', BillingEntry::class);

        $this->month = CarbonImmutable::now(config('platform.timezone'))->format('Y-m');
    }

    public function toggle(string $filter): void
    {
        match ($filter) {
            'duplicates' => $this->duplicatesOnly = ! $this->duplicatesOnly,
            'crew' => $this->crewOnly = ! $this->crewOnly,
            'content' => $this->contentOnly = ! $this->contentOnly,
            default => null,
        };
    }

    public function togglePanel(string $panel): void
    {
        $this->authorize('manage', BillingClient::class);

        $this->panel = $this->panel === $panel || ! in_array($panel, ['people', 'rates', 'clients'], true) ? '' : $panel;
    }

    public function chooseTechnician(int $id): void
    {
        $this->technician = $this->technician === (string) $id ? '' : (string) $id;
    }

    public function clearDay(): void
    {
        $this->day = '';
    }

    public function startTicketEdit(int $id): void
    {
        $entry = BillingEntry::query()->findOrFail($id);
        $this->authorize('correctTicket', $entry);
        $this->ticketEditId = $entry->id;
        $this->ticketValue = $entry->ticket;
        $this->ticketError = '';
    }

    public function cancelTicketEdit(): void
    {
        $this->reset('ticketEditId', 'ticketValue', 'ticketError');
    }

    /**
     * Supervisors and administrators correct a ticket number; the client's format still applies.
     */
    public function saveTicket(BillingJournal $journal): void
    {
        $entry = BillingEntry::query()->with('billingClient')->findOrFail($this->ticketEditId);
        $this->authorize('correctTicket', $entry);
        $ticket = trim($this->ticketValue);
        $client = $entry->billingClient;

        $this->ticketError = match (true) {
            $ticket === '' => __('billing.errors.no_ticket'),
            ! $client->acceptsTicket($ticket) => __('billing.errors.ticket_format', ['client' => $client->name, 'hint' => (string) $client->ticket_hint, 'example' => (string) $client->ticket_example]),
            default => '',
        };

        if ($this->ticketError !== '') {
            return;
        }

        $entry->update(['ticket' => $ticket]);
        $journal->record(BillingJournal::ENTRY_SAVED, $entry, $this->user(), $journal->entry($entry));
        $this->cancelTicketEdit();
    }

    public function edit(int $id): void
    {
        $this->authorize('updateAny', BillingEntry::class);
        $this->editEntryId = BillingEntry::query()->findOrFail($id)->id;
    }

    #[On('billing-entry-saved')]
    public function closeEdit(): void
    {
        $this->editEntryId = null;
    }

    public function showDetail(int $id): void
    {
        $this->authorize('viewAll', BillingEntry::class);
        $this->detailId = BillingEntry::query()->findOrFail($id)->id;
    }

    public function closeDetail(): void
    {
        $this->detailId = null;
    }

    public function render(BillingAccess $access, BillingCalculator $calculator, BillingChecks $checks, BillingPeriod $periods): View
    {
        $user = $this->user();
        $isAdministrator = $access->isAdministrator($user);
        $period = $this->day !== '' ? $this->day : $this->month;
        $periodEntries = $periods->entries($period);
        $context = $periods->context($periodEntries);
        $filtered = $periodEntries
            ->filter(fn (BillingEntry $entry) => $this->technician === '' || (string) $entry->user_id === $this->technician)
            ->filter(fn (BillingEntry $entry) => $this->type === '' || $entry->type->value === $this->type)
            ->filter(fn (BillingEntry $entry) => $this->client === '' || (string) $entry->billing_client_id === $this->client)
            ->values();

        $duplicates = $checks->duplicates($filtered);
        $crew = $filtered->filter(fn (BillingEntry $entry) => $checks->partnerMismatch($entry, $context) !== null)->modelKeys();
        $content = $filtered->filter(fn (BillingEntry $entry) => $checks->interventionMismatch($entry, $context) !== null)->modelKeys();
        $this->duplicatesOnly = $this->duplicatesOnly && $duplicates !== [];
        $this->crewOnly = $this->crewOnly && $crew !== [];
        $this->contentOnly = $this->contentOnly && $content !== [];

        $visible = $filtered
            ->filter(fn (BillingEntry $entry) => ! $this->duplicatesOnly || isset($duplicates[$entry->id]))
            ->filter(fn (BillingEntry $entry) => ! $this->crewOnly || in_array($entry->id, $crew, true))
            ->filter(fn (BillingEntry $entry) => ! $this->contentOnly || in_array($entry->id, $content, true))
            ->sortBy(fn (BillingEntry $entry) => [isset($duplicates[$entry->id]) || in_array($entry->id, $crew, true) || in_array($entry->id, $content, true) ? 0 : 1, -$entry->work_date->getTimestamp(), -$entry->created_at->getTimestamp()])
            ->values();
        $shares = $isAdministrator ? $filtered->mapWithKeys(fn (BillingEntry $entry) => [$entry->id => $calculator->share($entry, $context)]) : collect();
        $expenses = $this->expensesOf($period);

        return view('livewire.billing.dashboard', [
            'isAdministrator' => $isAdministrator,
            'period' => $period,
            'clients' => BillingClient::query()->orderBy('sort_order')->orderBy('name')->get(),
            'technicians' => $periodEntries->mapWithKeys(fn (BillingEntry $entry) => [$entry->user_id => $entry->user->name])->sort()->all(),
            'reminders' => $checks->missingPartners($periods->entries($this->month), $context),
            'filtered' => $filtered,
            'visible' => $visible,
            'duplicates' => $duplicates,
            'crew' => $crew,
            'content' => $content,
            'shares' => $shares,
            'grandTotal' => (float) $shares->sum(),
            'approvedTotal' => (float) $expenses->where('status', BillingExpenseStatus::Approved)->sum(fn (BillingExpense $expense) => (float) $expense->amount),
            'pendingTotal' => (float) $expenses->where('status', BillingExpenseStatus::Pending)->sum(fn (BillingExpense $expense) => (float) $expense->amount),
            'cards' => $this->cards($filtered, $shares->all(), $expenses),
            'detail' => $this->detailId === null ? null : BillingEntry::query()->with(['billingClient', 'interventionType', 'operator', 'partners:id,name', 'lines.article', 'photos', 'user:id,name'])->find($this->detailId),
            'userId' => $user->id,
            'editName' => $this->editEntryId === null ? '' : (string) BillingEntry::query()->with('user:id,name')->find($this->editEntryId)?->user->name,
        ]);
    }

    /**
     * One card per technician of the list: tickets, breakdowns and works, and for administrators their amount
     * and expenses.
     *
     * @param  Collection<int, BillingEntry>  $entries
     * @param  array<int, float>  $shares
     * @param  Collection<int, BillingExpense>  $expenses
     * @return list<array{id: int, name: string, breakdowns: int, works: int, amount: float, approved: float, pending: float}>
     */
    private function cards(Collection $entries, array $shares, Collection $expenses): array
    {
        $search = mb_strtolower(trim($this->technicianSearch));

        $cards = $entries->groupBy('user_id')->map(fn (Collection $group, int $userId) => [
            'id' => $userId,
            'name' => $group->first()->user->name,
            'breakdowns' => $group->where('type', BillingEntryType::Breakdown)->count(),
            'works' => $group->where('type', BillingEntryType::Works)->count(),
            'amount' => (float) $group->sum(fn (BillingEntry $entry) => $shares[$entry->id] ?? 0),
            'approved' => (float) $expenses->where('user_id', $userId)->where('status', BillingExpenseStatus::Approved)->sum(fn (BillingExpense $expense) => (float) $expense->amount),
            'pending' => (float) $expenses->where('user_id', $userId)->where('status', BillingExpenseStatus::Pending)->sum(fn (BillingExpense $expense) => (float) $expense->amount),
        ])->filter(fn (array $card) => $search === '' || str_contains(mb_strtolower($card['name']), $search));

        $sorted = match ($this->technicianSort) {
            'tickets_desc' => $cards->sortBy(fn (array $card) => [-($card['breakdowns'] + $card['works']), $card['name']]),
            'tickets_asc' => $cards->sortBy(fn (array $card) => [$card['breakdowns'] + $card['works'], $card['name']]),
            default => $cards->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE),
        };

        return $sorted->values()->all();
    }

    /**
     * @return Collection<int, BillingExpense>
     */
    private function expensesOf(string $period): Collection
    {
        return BillingExpense::query()
            ->when(strlen($period) === 10, fn ($query) => $query->whereDate('spent_on', $period), fn ($query) => $query->where('spent_on', 'like', $period.'-%'))
            ->when($this->technician !== '', fn ($query) => $query->where('user_id', (int) $this->technician))
            ->get();
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
