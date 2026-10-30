<button type="button" class="inventory__row" wire:click="openItem({{ $item->id }})" wire:key="item-{{ $item->id }}">
    @if ($item->photos->isNotEmpty())
        <img class="inventory__thumb" src="{{ route('vehicle-images.show', ['type' => 'inventory', 'id' => $item->photos->first()->id, 'variant' => 'thumb']) }}" alt="" loading="lazy">
    @else
        <span class="inventory__thumb inventory__thumb--empty" aria-hidden="true">📦</span>
    @endif
    <span class="inventory__row-main">
        <strong>{{ $item->label }}</strong>
        <span class="inventory__muted">
            @if ($item->brand || $item->model || $item->serial || $item->is_serialized)
                {{ collect([$item->brand, $item->model])->filter()->implode(' · ') }}{{ $item->serial ? ' · S/N '.$item->serial : ($item->is_serialized ? ' · '.__('inventory.no_serial') : '') }}
            @endif
        </span>
        @if ($showHolder && $item->holder)
            <span class="inventory__muted">👤 {{ $item->holder->name }}</span>
        @endif
        <span class="inventory__muted">
            @if ($item->status === \App\Enums\InventoryStatus::ToConfirm)
                {{ __('inventory.noted_on', ['date' => $item->noted_on?->translatedFormat('d/m/Y') ?? '—', 'site' => $item->site ?? '—']) }}
            @elseif ($item->holder_id && $item->assigned_on)
                {{ __('inventory.since', ['date' => $item->assigned_on->translatedFormat('d/m/Y')]) }}
            @endif
            @if ($item->vehicle) · 🚐 {{ $item->vehicle }}@endif
        </span>
        <span class="inventory__chips">
            @if ($item->trashed())
                <span class="inventory__chip inventory__chip--deleted">{{ __('inventory.deleted') }}</span>
            @else
                <span class="inventory__chip inventory__chip--{{ $item->status->value }}">{{ $item->status->label() }}</span>
            @endif
            @if ($item->condition !== \App\Enums\InventoryCondition::Unknown)
                <span class="inventory__chip inventory__chip--condition-{{ $item->condition->value }}">{{ $item->condition->label() }}</span>
            @endif
            @if ($item->needs_check)
                <span class="inventory__chip inventory__chip--to_confirm">⚠ {{ __('inventory.to_check') }}</span>
            @endif
            @if ($item->status === \App\Enums\InventoryStatus::Assigned && $item->hasSignedHandover())
                <span class="inventory__chip inventory__chip--assigned">✍ {{ __('inventory.signed_handover') }}</span>
            @elseif ($item->hasUnverifiedHandoverOnly())
                <span class="inventory__chip inventory__chip--attestation-unverified">{{ __('inventory.unverified_handover') }}</span>
            @elseif ($item->hasUnsignedHandoverOnly())
                <span class="inventory__chip inventory__chip--to_confirm">{{ __('inventory.unsigned_handover') }}</span>
            @endif
            @if ($item->read_status && $item->status === \App\Enums\InventoryStatus::ToConfirm && $item->read_status !== \App\Enums\EquipmentReadStatus::Ok)
                <span class="inventory__chip inventory__chip--to_confirm">{{ $item->read_status->label() }}</span>
            @endif
            @if ($item->read_message)
                <span class="inventory__chip inventory__chip--to_confirm">{{ __('inventory.label.errors.'.$item->read_message) }}</span>
            @endif
            @if ($item->pending_request)
                <span class="inventory__chip inventory__chip--repair">{{ __('inventory.request_pending.'.$item->pending_request['kind']) }}</span>
            @endif
        </span>
    </span>
    @if ($item->quantity > 1)
        <span class="inventory__quantity">× {{ $item->quantity }}</span>
    @endif
</button>
