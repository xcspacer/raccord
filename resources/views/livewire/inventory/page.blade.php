<div class="inventory-page">
    @if ($person)
        <div class="inventory-page__head">
            <a class="btn btn--ghost" href="{{ route('equipment.index') }}" wire:navigate>← {{ __('inventory.back') }}</a>
        </div>
        <livewire:inventory.panel mode="user" :user-id="$person->id" :key="'panel-'.$person->id" />
    @else
        <livewire:inventory.panel mode="central" :user-id="null" key="panel-central" />
    @endif

    <livewire:inventory.item-sheet />
    <livewire:inventory.bulk-add />
    <livewire:inventory.attestation />
</div>
