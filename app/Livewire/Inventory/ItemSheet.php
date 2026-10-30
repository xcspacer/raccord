<?php

namespace App\Livewire\Inventory;

use App\Actions\Files\UploadRules;
use App\Actions\Inventory\AssignItem;
use App\Actions\Inventory\ConfirmItems;
use App\Actions\Inventory\CreateItems;
use App\Actions\Inventory\DeclareAnomaly;
use App\Actions\Inventory\DeleteItem;
use App\Actions\Inventory\ManagePhotos;
use App\Actions\Inventory\ManageRequest;
use App\Actions\Inventory\ResolveAnomaly;
use App\Actions\Inventory\ReturnItem;
use App\Actions\Inventory\TransferItem;
use App\Actions\Inventory\UpdateItem;
use App\Enums\InventoryAnomalyKind;
use App\Enums\InventoryCategory;
use App\Enums\InventoryCondition;
use App\Enums\InventoryRequestKind;
use App\Enums\Role;
use App\Inventory\InventoryException;
use App\Models\InventoryItem;
use App\Models\InventoryItemPhoto;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * The sheet of an equipment line (details, history and the actions allowed to the signed-in person) and the
 * forms to record a new one or act on one. Every action is authorised and validated on the server; the line
 * and the expected holder are never taken from the browser.
 */
class ItemSheet extends Component
{
    use WithFileUploads;

    /**
     * The InventoryItemPolicy ability behind each action of the sheet.
     *
     * @var array<string, string>
     */
    private const array ABILITIES = [
        'restore' => 'restore', 'confirm' => 'confirm', 'assign' => 'assign', 'return' => 'takeBack', 'transfer' => 'transfer', 'resolve' => 'resolve',
        'close_request' => 'closeRequest', 'edit' => 'update', 'delete' => 'delete', 'manage_photos' => 'managePhotos', 'photos' => 'addPhotos',
        'anomaly' => 'declareAnomaly', 'request' => 'makeRequest',
    ];

    public bool $open = false;

    #[Locked]
    public ?int $itemId = null;

    #[Locked]
    public ?int $expectedHolderId = null;

    public string $action = '';

    /** @var list<TemporaryUploadedFile> */
    public array $newPhotos = [];

    /** @var list<TemporaryUploadedFile> */
    public array $proofs = [];

    public ?TemporaryUploadedFile $replacement = null;

    #[Locked]
    public ?int $replacingId = null;

    public ?int $zoomId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    #[On('inventory-item-open')]
    public function openFor(int $id): void
    {
        $item = InventoryItem::withTrashed()->findOrFail($id);
        $this->authorize('view', $item);

        $this->itemId = $item->id;
        $this->expectedHolderId = $item->holder_id;
        $this->action = '';
        $this->form = [];
        $this->resetErrorBag();
        $this->open = true;
    }

    #[On('inventory-new')]
    public function openNew(?int $holderId = null): void
    {
        $this->authorize('manage', InventoryItem::class);

        $this->itemId = null;
        $this->open = true;
        $this->action = 'create';
        $this->resetErrorBag();
        $this->form = [
            'label' => '', 'category' => '', 'condition' => 'good', 'brand' => '', 'model' => '', 'serial' => '', 'quantity' => 1, 'vehicle' => '',
            'holder' => $holderId === null ? '' : (string) $holderId, 'initial' => 'to_confirm', 'needs_check' => false, 'uncertainty' => '', 'notes' => '',
            'site' => '', 'date' => $this->today(),
        ];
    }

    #[On('inventory-assign')]
    public function openAssign(int $id, int $recipientId): void
    {
        $this->openFor($id);
        $this->startAction('assign');
        $this->form['recipient'] = (string) $recipientId;
    }

    public function close(): void
    {
        $this->proofs = [];
        $this->zoomId = null;
        $this->open = false;
        $this->action = '';
        $this->form = [];
    }

    public function startAction(string $action): void
    {
        $item = $this->item();
        $this->resetErrorBag();

        $this->authorizeAction($action, $item);

        $this->expectedHolderId = $item->holder_id;
        $this->proofs = [];
        $this->action = $action;
        $this->form = match ($action) {
            'edit' => [
                'label' => $item->label, 'category' => (string) $item->category, 'condition' => $item->condition->value, 'brand' => (string) $item->brand, 'model' => (string) $item->model,
                'serial' => (string) $item->serial, 'quantity' => $item->quantity, 'vehicle' => (string) $item->vehicle, 'needs_check' => $item->needs_check,
                'uncertainty' => (string) $item->uncertainty, 'notes' => (string) $item->notes,
            ],
            'assign' => ['recipient' => '', 'quantity' => $item->quantity, 'condition' => $item->condition === InventoryCondition::Unknown ? 'good' : $item->condition->value, 'date' => $this->today()],
            'return' => ['quantity' => $item->quantity, 'condition' => $item->condition->value, 'outcome' => 'stock', 'comment' => ''],
            'transfer' => ['recipient' => '', 'quantity' => $item->quantity, 'condition' => $item->condition->value, 'comment' => ''],
            'anomaly' => ['kind' => 'damaged', 'quantity' => $item->quantity, 'comment' => ''],
            'request' => ['kind' => 'return', 'comment' => ''],
            'resolve' => ['outcome' => $item->holder_id === null ? 'stock' : 'assigned', 'comment' => ''],
            default => ['comment' => ''],
        };
    }

    public function cancelAction(): void
    {
        $this->proofs = [];
        $this->action = '';
        $this->form = [];
        $this->resetErrorBag();
    }

    public function submit(): void
    {
        $this->resetErrorBag();

        try {
            match ($this->action) {
                'create' => $this->create(),
                'edit' => $this->edit(),
                'assign' => $this->assign(),
                'return' => $this->giveBack(),
                'transfer' => $this->transfer(),
                'anomaly' => $this->declareAnomaly(),
                'request' => $this->makeRequest(),
                'resolve' => $this->resolve(),
                'delete' => $this->delete(),
                default => abort(404),
            };
        } catch (InventoryException $exception) {
            $this->addError('form', $exception->translated());
        }
    }

    public function updatedNewPhotos(ManagePhotos $photos): void
    {
        $item = $this->item();
        $this->authorizeAction('photos', $item);

        $this->validate(['newPhotos' => ['array', 'max:'.ManagePhotos::MAX_PER_UPLOAD], 'newPhotos.*' => UploadRules::photo(16384)]);

        try {
            $photos->add($this->actor(), $item, $this->newPhotos);
        } catch (InvalidArgumentException) {
            $this->addError('newPhotos', __('inventory.errors.invalid_image'));
        }

        $this->newPhotos = [];
        $this->dispatch('inventory-changed');
    }

    public function startReplace(int $photoId): void
    {
        $this->authorizeAction('manage_photos', $this->item());

        $this->replacingId = $photoId;
    }

    public function updatedReplacement(ManagePhotos $photos): void
    {
        $item = $this->item();
        $this->authorizeAction('manage_photos', $item);
        abort_if($this->replacingId === null, 403);
        abort_unless($item->photos()->whereKey($this->replacingId)->exists(), 404);

        $this->validate(['replacement' => UploadRules::photo(16384)]);

        try {
            $photos->replace($this->actor(), $this->replacingId, $this->replacement);
        } catch (InvalidArgumentException) {
            $this->addError('replacement', __('inventory.errors.invalid_image'));
        }

        $this->replacement = null;
        $this->replacingId = null;
        $this->dispatch('inventory-changed');
    }

    public function removePhoto(int $photoId, ManagePhotos $photos): void
    {
        $item = $this->item();
        $this->authorizeAction('manage_photos', $item);
        abort_unless($item->photos()->whereKey($photoId)->exists(), 404);

        $photos->remove($this->actor(), $photoId);
        $this->dispatch('inventory-changed');
    }

    public function zoom(int $photoId): void
    {
        $item = $this->item();
        abort_unless(InventoryItemPhoto::query()->where('inventory_item_id', $item->id)->whereKey($photoId)->exists(), 404);

        $this->zoomId = $photoId;
    }

    public function closeZoom(): void
    {
        $this->zoomId = null;
    }

    public function confirmHandover(ConfirmItems $confirm): void
    {
        $item = $this->item();
        $this->authorizeAction('confirm', $item);

        $confirm->handle($this->actor(), [$item->id]);
        $this->finish();
    }

    public function closeRequest(ManageRequest $requests): void
    {
        $item = $this->item();
        $this->authorizeAction('close_request', $item);

        $requests->close($this->actor(), $item->id);
        $this->finish();
    }

    public function restore(DeleteItem $deleter): void
    {
        $item = $this->item();
        $this->authorizeAction('restore', $item);

        try {
            $deleter->restore($this->actor(), $item->id);
        } catch (InventoryException $exception) {
            $this->addError('form', $exception->translated());

            return;
        }

        $this->finish();
    }

    public function render(): View
    {
        $item = $this->open && $this->itemId !== null ? InventoryItem::withTrashed()->with(['holder.vehicle', 'creator', 'deleter', 'photos'])->find($this->itemId) : null;
        $user = $this->actor();
        $isManager = $user->can('manage', InventoryItem::class);

        return view('livewire.inventory.item-sheet', [
            'item' => $item,
            'actions' => $item === null ? [] : array_values(array_filter(['confirm', 'assign', 'return', 'transfer', 'resolve', 'close_request', 'edit', 'delete', 'restore', 'anomaly', 'request'], fn (string $name) => $this->allowed($name, $item))),
            'zoom' => $this->zoomId === null || $item === null ? null : $item->photos->firstWhere('id', $this->zoomId),
            'moves' => $item?->movements()->latest('id')->limit(60)->get() ?? [],
            'canAddPhotos' => $item !== null && $this->allowed('photos', $item),
            'canManagePhotos' => $item !== null && $this->allowed('manage_photos', $item),
            'categories' => InventoryCategory::cases(),
            'conditions' => InventoryCondition::cases(),
            'anomalyKinds' => InventoryAnomalyKind::cases(),
            'requestKinds' => InventoryRequestKind::cases(),
            'people' => $this->open && in_array($this->action, ['create', 'assign', 'transfer'], true) ? $this->people($item) : [],
            'isManager' => $isManager,
        ]);
    }

    /**
     * Whether the signed-in person may do an action on the line in its current state (InventoryItemPolicy).
     */
    private function allowed(string $action, InventoryItem $item): bool
    {
        return isset(self::ABILITIES[$action]) && $this->actor()->can(self::ABILITIES[$action], $item);
    }

    /**
     * Refuses (403) an action the signed-in person may not do on the line.
     */
    private function authorizeAction(string $action, InventoryItem $item): void
    {
        abort_unless(isset(self::ABILITIES[$action]), 403);
        $this->authorize(self::ABILITIES[$action], $item);
    }

    private function create(): void
    {
        $this->authorize('manage', InventoryItem::class);

        $data = $this->validated([
            'form.label' => ['required', 'string', 'max:120'], 'form.category' => ['nullable', Rule::enum(InventoryCategory::class)], 'form.condition' => ['required', Rule::enum(InventoryCondition::class)],
            'form.brand' => ['nullable', 'string', 'max:60'], 'form.model' => ['nullable', 'string', 'max:60'], 'form.serial' => ['nullable', 'string', 'max:80'],
            'form.quantity' => ['required', 'integer', 'min:1', 'max:9999'], 'form.vehicle' => ['nullable', 'string', 'max:60'], 'form.holder' => ['nullable', 'integer'],
            'form.initial' => ['required', Rule::in(['to_confirm', 'official'])], 'form.needs_check' => ['boolean'], 'form.uncertainty' => ['nullable', 'string', 'max:400'],
            'form.notes' => ['nullable', 'string', 'max:600'], 'form.site' => ['nullable', 'string', 'max:80'], 'form.date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $holder = ($data['holder'] ?? '') === '' || $data['holder'] === null ? null : User::query()->find((int) $data['holder']);

        if (($data['holder'] ?? '') !== '' && ($holder === null || $holder->hasRole(Role::DesignOffice->value))) {
            throw new InventoryException('bad_recipient');
        }

        $result = app(CreateItems::class)->handle($this->actor(), [$data], $holder, ['official' => $data['initial'] === 'official', 'date' => $data['date'] ?? null, 'site' => $data['site'] ?? null, 'source' => __('inventory.source_manual')]);

        if ($result['created'] === []) {
            $skip = $result['skipped'][0];
            throw new InventoryException($skip['reason'] === 'serial' ? 'serial_exists' : ($skip['reason'] === 'duplicate' ? 'duplicate' : 'label_required'), ['reference' => $skip['existing'] ?? '']);
        }

        $this->finish();
    }

    private function edit(): void
    {
        $item = $this->item();
        $this->authorizeAction('edit', $item);

        $data = $this->validated([
            'form.label' => ['required', 'string', 'max:120'], 'form.category' => ['nullable', Rule::enum(InventoryCategory::class)], 'form.condition' => ['required', Rule::enum(InventoryCondition::class)],
            'form.brand' => ['nullable', 'string', 'max:60'], 'form.model' => ['nullable', 'string', 'max:60'], 'form.serial' => ['nullable', 'string', 'max:80'],
            'form.quantity' => ['required', 'integer', 'min:1', 'max:9999'], 'form.vehicle' => ['nullable', 'string', 'max:60'], 'form.needs_check' => ['boolean'],
            'form.uncertainty' => ['nullable', 'string', 'max:400'], 'form.notes' => ['nullable', 'string', 'max:600'],
        ]);

        app(UpdateItem::class)->handle($this->actor(), $item->id, $data);
        $this->finish();
    }

    private function assign(): void
    {
        $item = $this->item();
        $this->authorizeAction('assign', $item);

        $data = $this->validated([
            'form.recipient' => ['required', 'integer'], 'form.quantity' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'form.condition' => ['nullable', Rule::enum(InventoryCondition::class)], 'form.date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        app(AssignItem::class)->handle($this->actor(), $item->id, (int) $data['recipient'], $this->quantity($data), $data['condition'] ?? null, $data['date'] ?? null);
        $this->finish();
    }

    private function giveBack(): void
    {
        $item = $this->item();
        $this->authorizeAction('return', $item);

        $data = $this->validated([
            'form.quantity' => ['nullable', 'integer', 'min:1', 'max:9999'], 'form.condition' => ['nullable', Rule::enum(InventoryCondition::class)],
            'form.outcome' => ['required', Rule::in(ReturnItem::OUTCOMES)], 'form.comment' => ['nullable', 'string', 'max:300'],
        ]);
        $this->validateProofs();

        $line = app(ReturnItem::class)->handle($this->actor(), $item->id, $this->quantity($data), $data['condition'] ?? null, $data['outcome'], $data['comment'] ?? null);
        $this->attachProofs($line);
        $this->finish();
    }

    private function transfer(): void
    {
        $item = $this->item();
        $this->authorizeAction('transfer', $item);

        $data = $this->validated([
            'form.recipient' => ['required', 'integer'], 'form.quantity' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'form.condition' => ['nullable', Rule::enum(InventoryCondition::class)], 'form.comment' => ['nullable', 'string', 'max:300'],
        ]);

        app(TransferItem::class)->handle($this->actor(), $item->id, (int) $data['recipient'], (int) $this->expectedHolderId, $this->quantity($data), $data['condition'] ?? null, $data['comment'] ?? null);
        $this->finish();
    }

    private function declareAnomaly(): void
    {
        $item = $this->item();
        $this->authorizeAction('anomaly', $item);

        $data = $this->validated([
            'form.kind' => ['required', Rule::enum(InventoryAnomalyKind::class)], 'form.quantity' => ['nullable', 'integer', 'min:1', 'max:9999'], 'form.comment' => ['required', 'string', 'max:600'],
        ]);
        $this->validateProofs();

        $line = app(DeclareAnomaly::class)->handle($this->actor(), $item->id, InventoryAnomalyKind::from($data['kind']), $data['comment'], $this->quantity($data));
        $this->attachProofs($line);
        $this->finish();
    }

    private function makeRequest(): void
    {
        $item = $this->item();
        $this->authorizeAction('request', $item);

        $data = $this->validated(['form.kind' => ['required', Rule::enum(InventoryRequestKind::class)], 'form.comment' => ['nullable', 'string', 'max:400']]);

        app(ManageRequest::class)->open($this->actor(), $item->id, InventoryRequestKind::from($data['kind']), $data['comment'] ?? null);
        $this->finish(__('inventory.request_sent'));
    }

    private function resolve(): void
    {
        $item = $this->item();
        $this->authorizeAction('resolve', $item);

        $data = $this->validated(['form.outcome' => ['required', Rule::in(ResolveAnomaly::OUTCOMES)], 'form.comment' => ['nullable', 'string', 'max:300']]);

        app(ResolveAnomaly::class)->handle($this->actor(), $item->id, $data['outcome'], $data['comment'] ?? null);
        $this->finish();
    }

    private function delete(): void
    {
        $item = $this->item();
        $this->authorizeAction('delete', $item);

        $data = $this->validated(['form.comment' => ['nullable', 'string', 'max:300']]);

        app(DeleteItem::class)->delete($this->actor(), $item->id, $data['comment'] ?? null);
        $this->finish();
    }

    /**
     * @param  array<string, mixed>  $rules  Rules keyed « form.field ».
     * @return array<string, mixed> Validated values keyed by field.
     */
    private function validated(array $rules): array
    {
        $this->validate($rules);

        $values = [];

        foreach (array_keys($rules) as $key) {
            $field = substr($key, 5);
            $values[$field] = $this->form[$field] ?? null;
        }

        return $values;
    }

    private function validateProofs(): void
    {
        $this->validate(['proofs' => ['array', 'max:'.ManagePhotos::MAX_PER_UPLOAD], 'proofs.*' => UploadRules::photo(16384)]);
    }

    private function attachProofs(InventoryItem $line): void
    {
        if ($this->proofs === []) {
            return;
        }

        try {
            app(ManagePhotos::class)->add($this->actor(), $line, $this->proofs, 'proof', $line->movements()->latest('id')->first());
        } catch (InvalidArgumentException) {
            // The operation is done; a proof that is not an image is simply not kept.
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function quantity(array $data): ?int
    {
        return isset($data['quantity']) && $data['quantity'] !== '' ? (int) $data['quantity'] : null;
    }

    private function finish(?string $message = null): void
    {
        $this->close();
        $this->dispatch('inventory-changed');
        $this->dispatch('toast', message: $message ?? __('inventory.saved'));
    }

    private function item(): InventoryItem
    {
        abort_if($this->itemId === null, 404);

        $item = InventoryItem::withTrashed()->findOrFail($this->itemId);
        $this->authorize('view', $item);

        return $item;
    }

    /**
     * @return Collection<int, User>
     */
    private function people(?InventoryItem $item): Collection
    {
        return User::query()->whereDoesntHave('roles', fn ($roles) => $roles->where('name', Role::DesignOffice->value))
            ->when($item?->holder_id !== null && $this->action === 'transfer', fn ($query) => $query->whereKeyNot($item?->holder_id))
            ->orderBy('name')->get(['id', 'name']);
    }

    private function today(): string
    {
        return CarbonImmutable::now(config('platform.timezone'))->toDateString();
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
