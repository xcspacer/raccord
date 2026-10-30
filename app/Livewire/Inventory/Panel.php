<?php

namespace App\Livewire\Inventory;

use App\Actions\Files\UploadRules;
use App\Actions\Inventory\ConfirmItems;
use App\Actions\Inventory\NoteOwnEquipment;
use App\Enums\EquipmentReadStatus;
use App\Enums\InventoryRequestKind;
use App\Enums\InventoryStatus;
use App\Enums\Module;
use App\Enums\Role;
use App\Jobs\ReadEquipmentLabel;
use App\Models\InventoryAttestation;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Settings\ModuleSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * The inventory as a list: everything (central), one person's file (user, for managers) or the equipment a
 * technician holds (mine). Three tabs, KPIs, search and filters; the actions live in the item sheet.
 */
class Panel extends Component
{
    use WithFileUploads;

    public ?TemporaryUploadedFile $labelPhoto = null;

    #[Locked]
    public string $mode = 'central';

    #[Locked]
    public ?int $userId = null;

    public string $tab = 'inventory';

    public string $filter = 'all';

    public string $search = '';

    public string $openUser = '';

    public function mount(): void
    {
        $user = $this->actor();

        if ($this->mode === 'mine') {
            abort_unless(! $user->hasRole(Role::DesignOffice->value) && app(ModuleSettings::class)->isEnabled(Module::Equipment), 403);
            $this->userId = $user->id;

            return;
        }

        abort_unless(in_array($this->mode, ['central', 'user'], true), 404);
        $this->authorize('viewAny', InventoryItem::class);
        abort_if($this->mode === 'user' && ($this->userId === null || User::query()->whereKey($this->userId)->doesntExist()), 404);
    }

    public function updatedOpenUser(): void
    {
        $this->authorize('viewAny', InventoryItem::class);

        $person = User::query()->whereKey((int) $this->openUser)->first();

        if ($person !== null && ! $person->hasRole(Role::DesignOffice->value)) {
            $this->redirectRoute('equipment.users.show', ['person' => $person->id], navigate: true);
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, $this->tabs(), true) ? $tab : 'inventory';
    }

    public function openItem(int $id): void
    {
        $item = InventoryItem::withTrashed()->findOrFail($id);
        $this->authorize('view', $item);

        abort_unless($this->inScope($item), 403);

        $this->dispatch('inventory-item-open', id: $id)->to(ItemSheet::class);
    }

    public function newItem(): void
    {
        $this->authorize('manage', InventoryItem::class);

        $this->dispatch('inventory-new', holderId: $this->mode === 'user' ? $this->userId : null)->to(ItemSheet::class);
    }

    public function updatedLabelPhoto(NoteOwnEquipment $note): void
    {
        abort_unless($this->mode === 'mine', 404);
        $this->authorize('note', InventoryItem::class);
        $this->resetErrorBag('labelPhoto');

        $this->validate(['labelPhoto' => ['required', ...UploadRules::photo(16384)]]);

        try {
            $item = $note->handle($this->actor(), $this->labelPhoto);
        } catch (InvalidArgumentException) {
            $this->addError('labelPhoto', __('inventory.errors.invalid_image'));
            $this->labelPhoto = null;

            return;
        }

        $this->labelPhoto = null;
        $this->tab = 'inventory';
        $this->filter = 'all';
        ReadEquipmentLabel::dispatchAfterResponse($this->actor()->id, $item->id);
    }

    public function openBulk(string $mode): void
    {
        $this->authorize('manage', InventoryItem::class);
        abort_unless(in_array($mode, ['photos', 'import'], true), 404);

        $this->dispatch('inventory-bulk', mode: $mode, holderId: $this->mode === 'user' ? $this->userId : null)->to(BulkAdd::class);
    }

    public function openAttestation(): void
    {
        $this->authorize('manage', InventoryItem::class);
        abort_unless($this->mode === 'user' && $this->userId !== null, 404);

        $this->dispatch('inventory-attestation', userId: $this->userId)->to(Attestation::class);
    }

    public function assignFromStock(int $id): void
    {
        $this->authorize('manage', InventoryItem::class);
        abort_unless($this->mode === 'user', 404);

        $this->dispatch('inventory-assign', id: $id, recipientId: $this->userId)->to(ItemSheet::class);
    }

    public function askConfirmAll(): void
    {
        $this->authorize('manage', InventoryItem::class);

        $items = $this->heldBy()->where('status', InventoryStatus::ToConfirm);

        if ($items->isEmpty()) {
            return;
        }

        $this->dispatch('confirm-action', message: __('inventory.confirm_all', ['count' => $items->sum('quantity'), 'name' => User::query()->find($this->userId)?->name]), method: 'confirmAll', componentId: $this->getId());
    }

    public function confirmAll(ConfirmItems $confirm): void
    {
        $this->authorize('manage', InventoryItem::class);

        $ids = $this->heldBy()->where('status', InventoryStatus::ToConfirm)->pluck('id')->all();
        $confirm->handle($this->actor(), $ids);

        $this->dispatch('toast', message: __('inventory.saved'));
    }

    #[On('inventory-changed')]
    public function refreshList(): void
    {
        // Re-render with the changes made in the item sheet.
    }

    public function render(): View
    {
        $isManager = $this->actor()->can('manage', InventoryItem::class);
        $items = $this->scoped($isManager);
        $visible = $this->filtered($items, $isManager);

        return view('livewire.inventory.panel', [
            'isManager' => $isManager,
            'reading' => $items->contains(fn (InventoryItem $item) => $item->read_status === EquipmentReadStatus::Reading),
            'canNote' => $this->mode === 'mine' && $this->actor()->can('note', InventoryItem::class),
            'kpis' => $this->kpis($items),
            'sections' => $this->sections($visible),
            'toConfirmCount' => $this->mode === 'central' ? 0 : $items->where('status', InventoryStatus::ToConfirm)->whereNull('deleted_at')->sum('quantity'),
            'hasDeleted' => $isManager && $items->contains(fn (InventoryItem $item) => $item->trashed()),
            'tabs' => $this->tabs(),
            'cards' => $this->tab === 'by-user' ? $this->userCards() : [],
            'people' => $this->tab === 'by-user' ? User::query()->whereDoesntHave('roles', fn ($roles) => $roles->where('name', Role::DesignOffice->value))->orderBy('name')->get(['id', 'name']) : [],
            'stock' => $this->mode === 'user' && $isManager ? InventoryItem::query()->where('status', InventoryStatus::Stock)->whereNull('holder_id')->orderBy('label')->get() : [],
            'moves' => $this->tab === 'history' ? $this->history() : [],
            'attestations' => $this->tab === 'attestations' ? InventoryAttestation::query()->where('user_id', $this->userId)->withCount('items')->latest('id')->get() : [],
            'vehicle' => $this->mode === 'central' ? null : $this->vehicleOf($this->userId),
            'requestKinds' => InventoryRequestKind::cases(),
        ]);
    }

    /**
     * @return list<string>
     */
    private function tabs(): array
    {
        return $this->mode === 'central' ? ['inventory', 'by-user', 'history'] : ['inventory', 'history', 'attestations'];
    }

    /**
     * @return Collection<int, InventoryItem>
     */
    private function scoped(bool $isManager): Collection
    {
        $query = InventoryItem::query()->with(['holder.vehicle', 'photos', 'attestations']);

        if ($isManager) {
            $query->withTrashed();
        }

        if ($this->mode !== 'central') {
            $query->where('holder_id', $this->userId);
        }

        return $query->orderBy('label')->get();
    }

    /**
     * @return Collection<int, InventoryItem>
     */
    private function heldBy(): Collection
    {
        return InventoryItem::query()->where('holder_id', $this->userId)->get();
    }

    private function inScope(InventoryItem $item): bool
    {
        return $this->mode === 'central' || $item->holder_id === $this->userId;
    }

    /**
     * @param  Collection<int, InventoryItem>  $items
     * @return array<string, int>
     */
    private function kpis(Collection $items): array
    {
        $active = $items->reject(fn (InventoryItem $item) => $item->trashed());
        $live = $active->reject(fn (InventoryItem $item) => $item->status === InventoryStatus::Retired);
        $pending = fn (InventoryItem $item) => ($item->pending_request['kind'] ?? null) === InventoryRequestKind::Return->value;

        if ($this->mode === 'central') {
            return [
                'equipment' => $live->count(),
                'stock' => $live->where('status', InventoryStatus::Stock)->count(),
                'assigned' => $live->where('status', InventoryStatus::Assigned)->count(),
                'to_confirm' => $live->where('status', InventoryStatus::ToConfirm)->count(),
                'repair' => $live->where('status', InventoryStatus::Repair)->count(),
                'problem' => $live->whereIn('status', [InventoryStatus::Lost, InventoryStatus::Damaged])->count(),
                'requests' => $live->filter($pending)->count(),
            ];
        }

        return [
            'assigned' => $live->where('status', InventoryStatus::Assigned)->sum('quantity'),
            'to_check' => $live->where('needs_check', true)->count(),
            'defective' => $live->filter(fn (InventoryItem $item) => $item->condition->value === 'defective' || in_array($item->status, [InventoryStatus::Damaged, InventoryStatus::Repair], true))->count(),
            'requests' => $live->filter($pending)->count(),
        ];
    }

    /**
     * @param  Collection<int, InventoryItem>  $items
     * @return Collection<int, InventoryItem>
     */
    private function filtered(Collection $items, bool $isManager): Collection
    {
        $words = array_filter(explode(' ', mb_strtolower(trim($this->search))));

        return $items->filter(function (InventoryItem $item) use ($words): bool {
            if ($this->filter === 'deleted') {
                $matches = $item->trashed();
            } elseif ($item->trashed()) {
                return false;
            } else {
                $matches = match ($this->filter) {
                    'to_confirm' => $item->status === InventoryStatus::ToConfirm,
                    'assigned' => $item->status === InventoryStatus::Assigned,
                    'stock' => $item->status === InventoryStatus::Stock,
                    'problem' => $item->status->isProblem(),
                    default => true,
                };

                if ($this->filter !== 'all' && $item->status === InventoryStatus::Retired) {
                    return false;
                }
            }

            if (! $matches) {
                return false;
            }

            $haystack = mb_strtolower(implode(' ', array_filter([
                $item->reference(), $item->label, $item->brand, $item->model, $item->serial, $item->category !== null ? __('enums.inventory_categories.'.$item->category) : null,
                $item->notes, $item->vehicle, $item->site, $item->holder?->name, $item->holder?->vehicle?->model, $item->status->label(),
            ])));

            foreach ($words as $word) {
                if (! str_contains($haystack, $word)) {
                    return false;
                }
            }

            return true;
        })->values();
    }

    /**
     * @param  Collection<int, InventoryItem>  $items
     * @return list<array{key: string, items: Collection<int, InventoryItem>}>
     */
    private function sections(Collection $items): array
    {
        if ($this->filter === 'deleted') {
            return $items->isEmpty() ? [] : [['key' => 'deleted', 'items' => $items]];
        }

        $definitions = [
            'to_confirm' => fn (InventoryItem $item) => $item->status === InventoryStatus::ToConfirm,
            'assigned' => fn (InventoryItem $item) => $item->status === InventoryStatus::Assigned,
            'stock' => fn (InventoryItem $item) => $item->status === InventoryStatus::Stock,
            'problem' => fn (InventoryItem $item) => $item->status->isProblem(),
            'retired' => fn (InventoryItem $item) => $item->status === InventoryStatus::Retired,
        ];

        if ($this->mode !== 'central') {
            unset($definitions['stock']);
        }

        $sections = [];

        foreach ($definitions as $key => $test) {
            $subset = $items->filter($test)->values();

            if ($subset->isNotEmpty()) {
                $sections[] = ['key' => $key, 'items' => $subset];
            }
        }

        return $sections;
    }

    /**
     * @return list<array{user: User, lines: int, to_confirm: int}>
     */
    private function userCards(): array
    {
        $held = InventoryItem::query()->whereNotNull('holder_id')->where('status', '!=', InventoryStatus::Retired->value)->with('holder.vehicle')->get();

        return $held->groupBy('holder_id')
            ->map(fn (Collection $lines) => ['user' => $lines->first()->holder, 'lines' => $lines->count(), 'to_confirm' => (int) $lines->where('status', InventoryStatus::ToConfirm)->sum('quantity')])
            ->filter(fn (array $card) => $card['user'] !== null)
            ->sortBy([['lines', 'desc'], fn (array $a, array $b) => strcmp($a['user']->name, $b['user']->name)])
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, InventoryMovement>
     */
    private function history(): Collection
    {
        $query = InventoryMovement::query()->latest('id')->limit(300);

        if ($this->mode !== 'central') {
            $id = $this->userId;
            $query->where(fn ($inner) => $inner->where('from_user_id', $id)->orWhere('to_user_id', $id)->orWhere('holder_id', $id));
        }

        return $query->get();
    }

    private function vehicleOf(?int $userId): ?string
    {
        $vehicle = User::query()->with('vehicle')->find($userId)?->vehicle;

        return $vehicle === null ? null : collect([$vehicle->model, $vehicle->plate])->filter()->implode(' - ');
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
