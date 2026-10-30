<span class="billing__rate">
    <input class="billing__input billing__rate-input" type="number" min="0" step="0.01" wire:model.blur="rates.{{ $name }}" aria-label="{{ $label }}">
    <span class="billing__muted">{{ $unit ?? '€' }}</span>
</span>
