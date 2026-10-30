<?php

namespace App\Livewire\Inventory;

use App\Actions\Inventory\CreateAttestation;
use App\Enums\InventoryStatus;
use App\Inventory\AttestationPdf;
use App\Inventory\InventoryException;
use App\Models\InventoryAttestation as InventoryAttestationModel;
use App\Models\InventoryItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Certificate of handover for the equipment a person holds: pick the lines, draw both signatures, preview the PDF
 * and archive it in the person's file. For managers only.
 */
class Attestation extends Component
{
    public bool $open = false;

    #[Locked]
    public ?int $userId = null;

    /** @var list<int> */
    public array $ids = [];

    public string $date = '';

    public string $representative = '';

    public string $observations = '';

    public string $technicianSignature = '';

    public string $representativeSignature = '';

    public ?string $preview = null;

    public bool $unsignedWarning = false;

    #[On('inventory-attestation')]
    public function openFor(int $userId): void
    {
        $this->authorize('create', InventoryAttestationModel::class);
        abort_if($this->holder($userId) === null, 404);

        $this->reset('preview', 'unsignedWarning', 'technicianSignature', 'representativeSignature', 'observations');
        $this->resetErrorBag();
        $this->userId = $userId;
        $this->date = CarbonImmutable::now(config('platform.timezone'))->toDateString();
        $this->representative = $this->actor()->name;
        $this->ids = $this->lines()->where('status', InventoryStatus::Assigned)->pluck('id')->all();
        $this->open = true;
    }

    public function close(): void
    {
        $this->reset('open', 'preview', 'unsignedWarning', 'technicianSignature', 'representativeSignature');
    }

    public function showPreview(AttestationPdf $pdf): void
    {
        $this->authorize('create', InventoryAttestationModel::class);
        $this->resetErrorBag();

        try {
            $items = $this->selected();
            $this->preview = base64_encode($pdf->render([
                'reference' => '—', 'holder' => $this->holder($this->userId), 'items' => $items, 'date' => CarbonImmutable::createFromFormat('!Y-m-d', $this->date) ?: CarbonImmutable::now(),
                'observations' => trim($this->observations) ?: null, 'representative' => trim($this->representative) ?: $this->actor()->name,
                'technicianSignature' => $this->decoded($this->technicianSignature), 'representativeSignature' => $this->decoded($this->representativeSignature),
            ]));
        } catch (InventoryException $exception) {
            $this->addError('form', $exception->translated());
        }
    }

    public function archive(CreateAttestation $create, bool $acceptUnsigned = false): void
    {
        $this->authorize('create', InventoryAttestationModel::class);
        $this->resetErrorBag();
        $this->validate(['date' => ['required', 'date_format:Y-m-d'], 'representative' => ['nullable', 'string', 'max:80'], 'observations' => ['nullable', 'string', 'max:800'], 'ids' => ['array']]);

        if (! $acceptUnsigned && ($this->technicianSignature === '' || $this->representativeSignature === '')) {
            $this->unsignedWarning = true;

            return;
        }

        $holder = $this->holder($this->userId);
        abort_if($holder === null, 404);

        try {
            $attestation = $create->handle($this->actor(), $holder, array_map('intval', $this->ids), [
                'date' => $this->date, 'observations' => $this->observations, 'representative' => $this->representative,
                'technician_signature' => $this->technicianSignature, 'representative_signature' => $this->representativeSignature,
            ]);
        } catch (InventoryException $exception) {
            $this->addError('form', $exception->translated());

            return;
        }

        $this->close();
        $this->dispatch('inventory-changed');
        $this->dispatch('toast', message: __('inventory.attestation_archived', ['reference' => $attestation->reference]));
    }

    public function render(): View
    {
        return view('livewire.inventory.attestation', ['lines' => $this->open ? $this->lines() : collect()]);
    }

    /**
     * @return Collection<int, InventoryItem>
     */
    private function lines(): Collection
    {
        return InventoryItem::query()->where('holder_id', $this->userId)->whereIn('status', [InventoryStatus::Assigned->value, InventoryStatus::ToConfirm->value])->orderBy('label')->get();
    }

    /**
     * @return Collection<int, InventoryItem>
     *
     * @throws InventoryException
     */
    private function selected(): Collection
    {
        $items = $this->lines()->whereIn('id', array_map('intval', $this->ids))->values();

        if ($items->isEmpty()) {
            throw new InventoryException('no_items');
        }

        return $items;
    }

    private function decoded(string $dataUrl): ?string
    {
        if (preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $match) !== 1 || strlen($match[1]) > 400000) {
            return null;
        }

        $bytes = base64_decode($match[1], true);

        return $bytes !== false && @imagecreatefromstring($bytes) !== false ? $bytes : null;
    }

    private function holder(?int $id): ?User
    {
        return $id === null ? null : User::query()->with('vehicle')->find($id);
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
