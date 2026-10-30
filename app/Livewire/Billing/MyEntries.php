<?php

namespace App\Livewire\Billing;

use App\Actions\Files\UploadRules;
use App\Billing\BillingAccess;
use App\Billing\BillingChecks;
use App\Billing\BillingJournal;
use App\Billing\EntryRules;
use App\Billing\SaveBillingEntry;
use App\Enums\BillingClosureStatus;
use App\Enums\BillingEntryType;
use App\Enums\BillingFormType;
use App\Enums\BillingSchedule;
use App\Models\BillingArticle;
use App\Models\BillingClient;
use App\Models\BillingEntry;
use App\Models\BillingEntryLine;
use App\Models\BillingInterventionType;
use App\Models\BillingOperator;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * « Mon pointage »: the person declares a breakdown or works for a billing client, with the form of that client,
 * the partners, articles and photos; colleagues' inconsistencies show live, and their entries are listed by month.
 *
 * @phpstan-import-type EntryInput from EntryRules
 */
class MyEntries extends Component
{
    use WithFileUploads;

    public const array PHOTO_GROUPS = ['none', 'before', 'after'];

    #[Locked]
    public ?int $editingId = null;

    public bool $pickingClient = true;

    public string $clientId = '';

    public string $type = 'breakdown';

    public string $date = '';

    public string $ticket = '';

    public string $typeId = '';

    public string $level = '';

    public string $schedule = '';

    public string $startTime = '';

    public string $endTime = '';

    public string $endDate = '';

    public string $operatorId = '';

    public string $closure = 'frozen';

    public bool $alone = false;

    /** @var list<int> */
    public array $partners = [];

    public string $comment = '';

    /** @var list<array{article: int, quantity: float}> */
    public array $lines = [];

    public string $articleSearch = '';

    public string $articleToAdd = '';

    public string $articleQuantity = '';

    public string $partnerSearch = '';

    public string $partnerToAdd = '';

    /** @var array<string, list<TemporaryUploadedFile>> photos just picked, merged into */
    public array $incoming = ['none' => [], 'before' => [], 'after' => []];

    /** @var array<string, list<TemporaryUploadedFile>> */
    public array $newPhotos = ['none' => [], 'before' => [], 'after' => []];

    /** @var list<int> */
    public array $removedPhotoIds = [];

    public string $error = '';

    public string $month = '';

    #[Locked]
    public ?int $detailId = null;

    /** Opened by an administrator from the dashboard to change another person's entry: only the form shows. */
    #[Locked]
    public bool $embedded = false;

    public function mount(?int $editEntryId = null): void
    {
        $this->authorize('create', BillingEntry::class);
        $user = $this->user();

        $this->clientId = (string) ($this->defaultClient($user)->id ?? '');
        $this->resetForm();

        if ($editEntryId !== null) {
            $this->authorize('updateAny', BillingEntry::class);
            $this->embedded = true;
            $this->edit($editEntryId);
        }
    }

    public function openClientPicker(): void
    {
        $this->pickingClient = true;
    }

    public function closeClientPicker(): void
    {
        $this->pickingClient = false;
    }

    /**
     * Changing the client starts a new form, on the type the client allows.
     */
    public function chooseClient(int $id): void
    {
        $client = BillingClient::query()->where('is_active', true)->findOrFail($id);

        $this->clientId = (string) $client->id;
        $this->type = $this->allowedType($client, $this->type);
        $this->pickingClient = false;
        $this->resetForm(keepType: true);
    }

    public function switchType(string $type): void
    {
        $client = $this->client();

        if ($client === null || $this->allowedType($client, $type) !== $type) {
            return;
        }

        $this->type = $type;
        $this->resetForm(keepType: true);
    }

    /**
     * The end date follows the start date until the person changes it.
     */
    public function updatedDate(): void
    {
        if ($this->endDate === '' || $this->endDate < $this->date) {
            $this->endDate = $this->date;
        }
    }

    public function updatedAlone(): void
    {
        if ($this->alone) {
            $this->partners = [];
            $this->partnerToAdd = '';
        }
    }

    public function updatedTypeId(): void
    {
        $type = $this->typeId === '' ? null : BillingInterventionType::query()->find((int) $this->typeId);

        if ($type !== null && ! $type->is_billable) {
            $this->level = '';
            $this->schedule = '';
        }
    }

    /**
     * @param  mixed  $value
     */
    public function updatedIncoming($value, string $key): void
    {
        $group = explode('.', $key)[0];

        if (in_array($group, self::PHOTO_GROUPS, true)) {
            $files = $this->incoming[$group];
            $this->validate(['incoming.'.$group.'.*' => UploadRules::photo(15360)]);
            $this->newPhotos[$group] = [...$this->newPhotos[$group], ...$files];
            $this->incoming[$group] = [];
        }
    }

    public function removeNewPhoto(string $group, int $index): void
    {
        if (isset($this->newPhotos[$group][$index])) {
            array_splice($this->newPhotos[$group], $index, 1);
        }
    }

    /**
     * A saved photo is only deleted when the entry is saved.
     */
    public function removePhoto(int $id): void
    {
        $this->removedPhotoIds[] = $id;
    }

    public function addLine(): void
    {
        $article = BillingArticle::query()->where('is_active', true)->find((int) $this->articleToAdd);
        $quantity = (float) str_replace(',', '.', $this->articleQuantity);

        if ($article === null || $quantity <= 0) {
            return;
        }

        foreach ($this->lines as $index => $line) {
            if ($line['article'] === $article->id) {
                $this->lines[$index]['quantity'] = $line['quantity'] + $quantity;
                $this->reset('articleToAdd', 'articleQuantity');

                return;
            }
        }

        $this->lines[] = ['article' => $article->id, 'quantity' => $quantity];
        $this->reset('articleToAdd', 'articleQuantity');
    }

    public function removeLine(int $articleId): void
    {
        $this->lines = array_values(array_filter($this->lines, fn (array $line) => $line['article'] !== $articleId));
    }

    public function addPartner(BillingAccess $access): void
    {
        $partner = $access->partnerCandidates($this->subject())->firstWhere('id', (int) $this->partnerToAdd);

        if ($partner !== null && ! in_array($partner->id, $this->partners, true)) {
            $this->partners[] = $partner->id;
            $this->alone = false;
        }

        $this->partnerToAdd = '';
    }

    public function removePartner(int $id): void
    {
        $this->partners = array_values(array_diff($this->partners, [$id]));
    }

    public function save(EntryRules $rules, SaveBillingEntry $saver, BillingAccess $access): void
    {
        $this->authorize('create', BillingEntry::class);
        $user = $this->user();

        $client = $this->client();
        $entry = $this->editingId === null ? null : $this->ownEntry($this->editingId);
        $this->ticket = trim($this->ticket);
        $this->error = '';

        if ($client === null) {
            $this->pickingClient = true;

            return;
        }

        $this->partners = array_values(array_intersect($this->partners, $access->partnerCandidates($this->subject())->modelKeys()));
        $error = $rules->firstError($client, $this->input());

        if ($error !== null) {
            $this->error = $error;

            return;
        }

        $saver->handle($entry->user ?? $user, $client, $this->input(), $entry, array_filter($this->newPhotos), $this->removedPhotoIds, $user);

        if ($this->embedded) {
            $this->dispatch('billing-entry-saved');
            $this->dispatch('toast', message: __('billing.saved'));

            return;
        }

        $editing = $this->editingId !== null;
        $this->editingId = null;

        if ($editing) {
            $this->clientId = (string) ($this->defaultClient($user)->id ?? $client->id);
            $this->type = $this->allowedType($this->client() ?? $client, 'breakdown');
        }

        $this->resetForm(keepType: true);
        $this->dispatch('toast', message: __('billing.saved'));
    }

    public function edit(int $id): void
    {
        $entry = $this->ownEntry($id)->load(['partners', 'lines']);

        $this->editingId = $entry->id;
        $this->pickingClient = false;
        $this->clientId = (string) $entry->billing_client_id;
        $this->type = $entry->type->value;
        $this->date = $entry->work_date->toDateString();
        $this->ticket = $entry->ticket;
        $this->typeId = (string) ($entry->billing_intervention_type_id ?? '');
        $this->level = (string) ($entry->level ?? '');
        $this->schedule = (string) ($entry->schedule->value ?? '');
        $this->startTime = (string) $entry->started_at?->format('H:i');
        $this->endTime = (string) $entry->ended_at?->format('H:i');
        $this->endDate = (string) ($entry->ended_at?->toDateString() ?? $this->date);
        $this->operatorId = (string) ($entry->billing_operator_id ?? '');
        $this->closure = $entry->closure_status->value ?? BillingClosureStatus::Frozen->value;
        $this->alone = $entry->is_alone;
        $this->partners = $entry->partners->modelKeys();
        $this->comment = $entry->comment;
        $this->lines = $entry->lines->map(fn (BillingEntryLine $line) => ['article' => $line->billing_article_id, 'quantity' => (float) $line->quantity])->values()->all();
        $this->newPhotos = ['none' => [], 'before' => [], 'after' => []];
        $this->removedPhotoIds = [];
        $this->error = '';
    }

    /**
     * Cancelling an edit goes back to the person's default client.
     */
    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->clientId = (string) ($this->defaultClient($this->user())->id ?? $this->clientId);
        $this->type = $this->client() === null ? 'breakdown' : $this->allowedType($this->client(), 'breakdown');
        $this->resetForm(keepType: true);
    }

    public function delete(int $id, BillingJournal $journal): void
    {
        $entry = $this->ownEntry($id, 'delete');
        $entry->forceFill(['deleted_by' => $this->user()->id])->save();
        $journal->record(BillingJournal::ENTRY_DELETED, $entry, $this->user(), $journal->entry($entry));
        $entry->delete();

        if ($this->editingId === $id) {
            $this->cancelEdit();
        }
    }

    public function showDetail(int $id): void
    {
        $this->detailId = $this->visibleEntries()->firstWhere('id', $id)?->id;
    }

    public function closeDetail(): void
    {
        $this->detailId = null;
    }

    public function chooseMonth(string $month): void
    {
        $this->month = preg_match('/^\d{4}-\d{2}$/', $month) === 1 ? $month : '';
    }

    public function render(BillingAccess $access, BillingChecks $checks, EntryRules $rules): View
    {
        $user = $this->user();
        $client = $this->client();
        $entries = $this->visibleEntries();
        $mine = $entries->where('user_id', $user->id)->sortByDesc(fn (BillingEntry $entry) => $entry->work_date->format('Ymd').str_pad((string) $entry->created_at->getTimestamp(), 12, '0', STR_PAD_LEFT))->values();
        $months = $mine->groupBy(fn (BillingEntry $entry) => $entry->work_date->format('Y-m'));
        $activeMonth = $months->has($this->month) ? $this->month : (string) $months->keys()->first();
        $others = $entries->where('user_id', '!=', $user->id);
        $partnerMismatches = $mine->mapWithKeys(fn (BillingEntry $entry) => [$entry->id => $checks->partnerMismatch($entry, $others)])->filter();
        $contentMismatches = $mine->mapWithKeys(fn (BillingEntry $entry) => [$entry->id => $checks->interventionMismatch($entry, $others)])->filter();
        $monthEntries = ($months->get($activeMonth) ?? new Collection)
            ->sortBy(fn (BillingEntry $entry) => $partnerMismatches->has($entry->id) || $contentMismatches->has($entry->id) ? 0 : 1)
            ->values();
        $draft = $client === null ? null : $this->draft($client, $user);
        $type = $client === null || $this->typeId === '' ? null : $client->interventionTypes->firstWhere('id', (int) $this->typeId);
        $candidates = $access->partnerCandidates($this->subject());
        $search = mb_strtolower(trim($this->partnerSearch));
        $articleSearch = mb_strtolower(trim($this->articleSearch));
        $articles = BillingArticle::query()->where('is_active', true)->orderBy('sort_order')->orderBy('code')->get();

        return view('livewire.billing.my-entries', [
            'clients' => BillingClient::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'client' => $client,
            'chosenType' => $type,
            'timed' => $client !== null && $rules->isTimed($client, $this->type === BillingEntryType::Works->value, $type),
            'operators' => BillingOperator::query()->orderBy('name')->get(),
            'articles' => $articles,
            'articleOptions' => $articles->filter(fn (BillingArticle $article) => $articleSearch === '' || str_contains(mb_strtolower($article->code.' '.$article->label), $articleSearch)),
            'candidates' => $candidates,
            'partnerOptions' => $candidates->reject(fn (User $candidate) => in_array($candidate->id, $this->partners, true))->filter(fn (User $candidate) => $search === '' || str_contains(mb_strtolower($candidate->name), $search)),
            'existingPhotos' => $this->editingId === null ? new Collection : $this->ownEntry($this->editingId)->photos()->whereNotIn('id', $this->removedPhotoIds)->get(),
            'reminders' => array_values(array_filter($checks->missingPartners($others, $mine), fn (array $reminder) => $reminder['partner']->id === $user->id)),
            'partnerMismatches' => $partnerMismatches,
            'contentMismatches' => $contentMismatches,
            'liveCrew' => $draft === null ? null : $checks->partnerMismatch($draft, $others, othersOnly: true),
            'liveContent' => $draft === null || ! $this->hasDescription($client) ? null : $checks->interventionMismatch($draft, $others, othersOnly: true),
            'months' => $months->map(fn (Collection $group) => $group->count()),
            'activeMonth' => $activeMonth,
            'monthEntries' => $monthEntries,
            'typeSummary' => $this->typeSummary($months->get($activeMonth) ?? new Collection),
            'monthCount' => $mine->filter(fn (BillingEntry $entry) => $entry->work_date->format('Y-m') === now()->format('Y-m'))->count(),
            'detail' => $this->detailId === null ? null : $entries->firstWhere('id', $this->detailId),
            'userId' => $user->id,
        ]);
    }

    /**
     * @return EntryInput
     */
    private function input(): array
    {
        return [
            'type' => $this->type,
            'date' => $this->date,
            'ticket' => $this->ticket,
            'typeId' => $this->typeId === '' ? null : (int) $this->typeId,
            'level' => $this->level === '' ? null : (int) $this->level,
            'schedule' => $this->schedule === '' ? null : $this->schedule,
            'startTime' => $this->startTime,
            'endTime' => $this->endTime,
            'endDate' => $this->endDate,
            'operatorId' => $this->operatorId === '' ? null : (int) $this->operatorId,
            'closure' => $this->closure,
            'alone' => $this->alone,
            'partners' => $this->partners,
            'comment' => $this->comment,
            'lines' => $this->lines,
        ];
    }

    /**
     * The form as an unsaved entry, to compare it live with colleagues' entries of the same ticket.
     */
    private function draft(BillingClient $client, User $user): ?BillingEntry
    {
        if (trim($this->ticket) === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date) !== 1) {
            return null;
        }

        $interval = app(EntryRules::class)->interval($this->input());
        $draft = new BillingEntry([
            'type' => BillingEntryType::from($this->type),
            'billing_client_id' => $client->id,
            'user_id' => $user->id,
            'work_date' => $this->date,
            'ticket' => trim($this->ticket),
            'billing_intervention_type_id' => $this->typeId === '' ? null : (int) $this->typeId,
            'level' => $this->level === '' ? null : (int) $this->level,
            'schedule' => BillingSchedule::tryFrom($this->schedule),
            'started_at' => $interval[0] ?? null,
            'ended_at' => $interval[1] ?? null,
            'billing_operator_id' => $this->operatorId === '' ? null : (int) $this->operatorId,
            'closure_status' => BillingClosureStatus::tryFrom($this->closure),
        ]);
        $draft->setRelation('billingClient', $client);
        $draft->setRelation('partners', User::query()->whereIn('id', $this->alone ? [] : $this->partners)->get());
        $draft->setRelation('lines', new Collection(array_map(fn (array $line) => new BillingEntryLine(['billing_article_id' => $line['article'], 'quantity' => $line['quantity']]), $this->lines)));

        if ($this->editingId !== null) {
            $draft->id = $this->editingId;
            $draft->exists = true;
        }

        return $draft;
    }

    /**
     * The live description check waits until the intervention is described.
     */
    private function hasDescription(BillingClient $client): bool
    {
        $works = $this->type === BillingEntryType::Works->value;

        return match (true) {
            $works && $client->form_type === BillingFormType::DayRatePair => $this->typeId !== '',
            $works => $this->lines !== [],
            $client->form_type->isTimed() => $this->startTime !== '' && $this->endTime !== '',
            default => $this->typeId !== '',
        };
    }

    /**
     * Count by intervention type of the month (works priced by article and timed forms are left out).
     *
     * @param  Collection<int, BillingEntry>  $entries
     * @return BaseCollection<string, int>
     */
    private function typeSummary(Collection $entries): BaseCollection
    {
        return $entries
            ->filter(fn (BillingEntry $entry) => $entry->interventionType !== null)
            ->countBy(fn (BillingEntry $entry) => $entry->interventionType->summaryLabel());
    }

    /**
     * The person's entries and those they are named on as a partner.
     *
     * @return Collection<int, BillingEntry>
     */
    private function visibleEntries(): Collection
    {
        $user = $this->user();

        return BillingEntry::query()
            ->with(['billingClient', 'interventionType', 'operator', 'partners:id,name', 'lines.article', 'photos', 'user:id,name'])
            ->where(fn ($query) => $query->where('user_id', $user->id)->orWhereHas('partners', fn ($partners) => $partners->whereKey($user->id)))
            ->get();
    }

    /**
     * An entry the person may change: their own, or any entry for an administrator (BillingEntryPolicy).
     */
    private function ownEntry(int $id, string $ability = 'update'): BillingEntry
    {
        $entry = BillingEntry::query()->findOrFail($id);
        $this->authorize($ability, $entry);

        return $entry;
    }

    /**
     * Whose entry the form describes: the signed-in person, or the entry's technician when an administrator edits it.
     */
    private function subject(): User
    {
        if ($this->editingId !== null) {
            return $this->ownEntry($this->editingId)->user;
        }

        return $this->user();
    }

    private function client(): ?BillingClient
    {
        return $this->clientId === '' ? null : BillingClient::query()->with('interventionTypes')->where('is_active', true)->find((int) $this->clientId);
    }

    private function defaultClient(User $user): ?BillingClient
    {
        return $user->billingProfile->defaultClient ?? BillingClient::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->first();
    }

    /**
     * Works only exist for clients with works; a works-only client never shows breakdowns.
     */
    private function allowedType(BillingClient $client, string $wanted): string
    {
        if (! $client->has_breakdown) {
            return BillingEntryType::Works->value;
        }

        return $wanted === BillingEntryType::Works->value && $client->has_works ? BillingEntryType::Works->value : BillingEntryType::Breakdown->value;
    }

    private function resetForm(bool $keepType = false): void
    {
        $this->reset('ticket', 'typeId', 'level', 'schedule', 'startTime', 'endTime', 'operatorId', 'closure', 'alone', 'partners', 'comment', 'lines', 'articleSearch', 'articleToAdd', 'articleQuantity', 'partnerSearch', 'partnerToAdd', 'incoming', 'newPhotos', 'removedPhotoIds', 'error');

        if (! $keepType) {
            $this->type = BillingEntryType::Breakdown->value;
        }

        $this->date = CarbonImmutable::now(config('platform.timezone'))->toDateString();
        $this->endDate = $this->date;
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
