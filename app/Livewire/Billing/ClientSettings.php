<?php

namespace App\Livewire\Billing;

use App\Billing\BillingJournal;
use App\Enums\BillingFormType;
use App\Models\BillingArticle;
use App\Models\BillingClient;
use App\Models\BillingEntry;
use App\Models\BillingInterventionType;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * « Gérer les clients »: the billing clients with their form type and ticket format, the intervention types of
 * each one, and the works price list shared by the clients with works.
 */
class ClientSettings extends Component
{
    /** Characters Excel refuses in a sheet name. */
    private const string SHEET_NAME = '/^[^\\\\\/\?\*\[\]:]+$/';

    #[Locked]
    public ?int $editingId = null;

    public string $name = '';

    public string $sheetLabel = '';

    public string $worksSheetLabel = '';

    public string $formType = 'type_rate';

    public bool $hasBreakdown = true;

    public bool $hasWorks = false;

    public bool $hasTravelFee = false;

    public string $ticketPattern = '';

    public string $ticketHint = '';

    public string $ticketExample = '';

    public string $sortOrder = '0';

    public bool $isActive = true;

    public string $typeLabel = '';

    public string $typeShortLabel = '';

    public string $typeSheetLabel = '';

    public bool $typeBillable = true;

    public string $articleCode = '';

    public string $articleLabel = '';

    public string $articleUnit = '';

    public bool $articleCoefficient = false;

    public function mount(): void
    {
        $this->guard();
    }

    public function edit(int $id): void
    {
        $this->guard();
        $client = BillingClient::query()->findOrFail($id);

        $this->editingId = $client->id;
        $this->name = $client->name;
        $this->sheetLabel = $client->sheet_label;
        $this->worksSheetLabel = (string) $client->works_sheet_label;
        $this->formType = $client->form_type->value;
        $this->hasBreakdown = $client->has_breakdown;
        $this->hasWorks = $client->has_works;
        $this->hasTravelFee = $client->has_travel_fee;
        $this->ticketPattern = (string) $client->ticket_pattern;
        $this->ticketHint = (string) $client->ticket_hint;
        $this->ticketExample = (string) $client->ticket_example;
        $this->sortOrder = (string) $client->sort_order;
        $this->isActive = $client->is_active;
        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->reset();
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->guard();

        $data = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'sheetLabel' => ['required', 'string', 'max:31', 'regex:'.self::SHEET_NAME],
            'worksSheetLabel' => ['nullable', 'string', 'max:31', 'regex:'.self::SHEET_NAME],
            'formType' => ['required', Rule::enum(BillingFormType::class)],
            'ticketPattern' => ['nullable', 'string', 'max:160', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value !== null && $value !== '' && @preg_match('/'.str_replace('/', '\/', (string) $value).'/i', '') === false) {
                    $fail(__('billing.settings.pattern_invalid'));
                }
            }],
            'ticketHint' => ['nullable', 'string', 'max:160'],
            'ticketExample' => ['nullable', 'string', 'max:40'],
            'sortOrder' => ['required', 'integer', 'between:0,999'],
        ]);
        $form = BillingFormType::from($data['formType']);

        $attributes = [
            'name' => trim($data['name']),
            'sheet_label' => trim($data['sheetLabel']),
            'works_sheet_label' => $form !== BillingFormType::DayRatePair ? (trim((string) $data['worksSheetLabel']) ?: null) : null,
            'form_type' => $form,
            'has_breakdown' => $form !== BillingFormType::DayRatePair && $this->hasBreakdown,
            'has_works' => $form === BillingFormType::DayRatePair || $this->hasWorks,
            'has_travel_fee' => $form === BillingFormType::LevelSchedule && $this->hasTravelFee,
            'ticket_pattern' => trim((string) $data['ticketPattern']) ?: null,
            'ticket_hint' => trim((string) $data['ticketHint']) ?: null,
            'ticket_example' => trim((string) $data['ticketExample']) ?: null,
            'sort_order' => (int) $data['sortOrder'],
            'is_active' => $this->isActive,
        ];

        $client = $this->editingId === null ? new BillingClient : BillingClient::query()->findOrFail($this->editingId);
        $client->fill($attributes)->save();
        $this->editingId = $client->id;
        app(BillingJournal::class)->record(BillingJournal::CLIENT_SAVED, $client, $this->user());

        $this->dispatch('toast', message: __('billing.settings.client_saved'));
    }

    public function addType(): void
    {
        $this->guard();

        $client = BillingClient::query()->findOrFail($this->editingId);
        $this->validate([
            'typeLabel' => ['required', 'string', 'max:80'],
            'typeShortLabel' => ['nullable', 'string', 'max:40'],
            'typeSheetLabel' => ['nullable', 'string', 'max:31', 'regex:'.self::SHEET_NAME],
        ]);

        $type = $client->interventionTypes()->create([
            'label' => trim($this->typeLabel),
            'short_label' => trim($this->typeShortLabel) ?: null,
            'sheet_label' => $client->form_type === BillingFormType::DayRatePair ? (trim($this->typeSheetLabel) ?: null) : null,
            'is_billable' => $this->typeBillable,
            'sort_order' => (int) $client->interventionTypes()->max('sort_order') + 1,
        ]);
        app(BillingJournal::class)->record(BillingJournal::TYPE_ADDED, $type, $this->user());
        $this->reset('typeLabel', 'typeShortLabel', 'typeSheetLabel', 'typeBillable');
    }

    /**
     * A type already used by entries is kept; an unused one is removed.
     */
    public function removeType(int $id): void
    {
        $this->guard();

        $type = BillingInterventionType::query()->where('billing_client_id', $this->editingId)->findOrFail($id);

        if (BillingEntry::withTrashed()->where('billing_intervention_type_id', $type->id)->exists()) {
            $this->addError('typeLabel', __('billing.settings.type_used'));

            return;
        }

        app(BillingJournal::class)->record(BillingJournal::TYPE_REMOVED, $type, $this->user());
        $type->delete();
    }

    public function addArticle(): void
    {
        $this->guard();

        $this->validate([
            'articleCode' => ['required', 'string', 'max:40', Rule::unique('billing_articles', 'code')],
            'articleLabel' => ['required', 'string', 'max:255'],
            'articleUnit' => ['required', 'string', 'max:12'],
        ]);

        $article = BillingArticle::query()->create([
            'code' => trim($this->articleCode),
            'label' => trim($this->articleLabel),
            'unit' => trim($this->articleUnit),
            'is_coefficient' => $this->articleCoefficient,
            'sort_order' => (int) BillingArticle::query()->max('sort_order') + 1,
        ]);
        app(BillingJournal::class)->record(BillingJournal::ARTICLE_ADDED, $article, $this->user());
        $this->reset('articleCode', 'articleLabel', 'articleUnit', 'articleCoefficient');
    }

    /**
     * Articles are never deleted (old works keep them); they are switched off.
     */
    public function toggleArticle(int $id): void
    {
        $this->guard();

        $article = BillingArticle::query()->findOrFail($id);
        $article->update(['is_active' => ! $article->is_active]);
        app(BillingJournal::class)->record(BillingJournal::ARTICLE_TOGGLED, $article, $this->user());
    }

    public function render(): View
    {
        return view('livewire.billing.client-settings', [
            'clients' => BillingClient::query()->withCount('interventionTypes')->orderBy('sort_order')->orderBy('name')->get(),
            'editing' => $this->editingId === null ? null : BillingClient::query()->with('interventionTypes')->find($this->editingId),
            'articles' => BillingArticle::query()->orderBy('sort_order')->orderBy('code')->get(),
        ]);
    }

    private function guard(): void
    {
        $this->authorize('manage', BillingClient::class);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
