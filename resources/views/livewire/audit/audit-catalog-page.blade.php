<div class="audit">
    <a class="btn btn--ghost audit__back" href="{{ route('audits.full') }}" wire:navigate>← {{ __('audit.back') }}</a>
    <p class="audit__muted">{{ __('audit.catalog.hint') }}</p>

    @foreach ($lists as $list)
        <div class="field" wire:key="catalog-{{ $list->value }}">
            <label class="field__label" for="catalog-{{ $list->value }}">{{ $list->label() }}</label>
            <textarea class="field__input" id="catalog-{{ $list->value }}" rows="8" wire:model="texts.{{ $list->value }}" placeholder="{{ __('audit.catalog.placeholder') }}"></textarea>
            @error('texts.'.$list->value) <p class="field__error">{{ $message }}</p> @enderror
        </div>
    @endforeach

    <div class="form-actions form-actions--left">
        <button type="button" class="btn btn--primary" wire:click="save">{{ __('audit.catalog.save') }}</button>
    </div>
</div>
