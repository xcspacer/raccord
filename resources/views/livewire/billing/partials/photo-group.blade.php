<div class="billing__block">
    <span class="billing__label">{{ $label }}</span>
    <label class="file-picker" x-data="photoPicker" data-property="incoming.{{ $group }}" data-pick="{{ __('billing.form.photos_pick') }}" data-sending="{{ __('billing.form.photos_sending') }}">
        <span class="billing__button billing__button--small" x-text="label">{{ __('billing.form.photos_pick') }}</span>
        <input class="file-picker__input" type="file" accept="image/jpeg,image/png,image/webp" multiple x-on:change="pick" x-bind:disabled="busy">
    </label>
    @error('incoming.'.$group.'.*') <p class="billing__error" role="alert">{{ __('billing.form.photos_failed') }}</p> @enderror
    @if ($existing->isNotEmpty() || ($newPhotos[$group] ?? []) !== [])
        <div class="billing__thumbs">
            @foreach ($existing as $photo)
                <span class="billing__thumb" wire:key="saved-photo-{{ $photo->id }}">
                    <img src="{{ route('billing.photos', [$photo->id, 'thumb']) }}" alt="">
                    <button type="button" wire:click="removePhoto({{ $photo->id }})" aria-label="{{ __('billing.form.photo_remove') }}">✕</button>
                </span>
            @endforeach
            @foreach ($newPhotos[$group] ?? [] as $index => $photo)
                <span class="billing__thumb" wire:key="new-photo-{{ $group }}-{{ $index }}">
                    @if ($photo->isPreviewable())
                        <img src="{{ $photo->temporaryUrl() }}" alt="">
                    @endif
                    <button type="button" wire:click="removeNewPhoto('{{ $group }}', {{ $index }})" aria-label="{{ __('billing.form.photo_remove') }}">✕</button>
                </span>
            @endforeach
        </div>
    @endif
</div>
