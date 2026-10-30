<div class="field-row">
    <div class="field">
        <label class="field__label" for="ws-client">{{ __('work_sites.fields.client') }} *</label>
        <input class="field__input" id="ws-client" list="ws-clients" wire:model="form.client" autocomplete="off">
        <datalist id="ws-clients">
            @foreach ($clients as $name)
                <option value="{{ $name }}"></option>
            @endforeach
        </datalist>
        @error('form.client') <p class="field__error">{{ $message }}</p> @enderror
    </div>
    <div class="field">
        <label class="field__label" for="ws-city">{{ __('work_sites.fields.city') }} *</label>
        <input class="field__input" id="ws-city" wire:model="form.city" autocomplete="off">
        @error('form.city') <p class="field__error">{{ $message }}</p> @enderror
    </div>
</div>

<div class="field-row">
    <div class="field">
        <label class="field__label" for="ws-starts">{{ __('work_sites.fields.starts_on') }} *</label>
        <input class="field__input" type="date" id="ws-starts" wire:model.live="form.startsOn">
        @error('form.startsOn') <p class="field__error">{{ $message }}</p> @enderror
    </div>
    <div class="field">
        <label class="field__label" for="ws-ends">{{ __('work_sites.fields.ends_on') }} *</label>
        <input class="field__input" type="date" id="ws-ends" wire:model.live="form.endsOn">
    </div>
</div>
@error('form.endsOn') <p class="field__error">{{ $message }}</p> @enderror

<div class="field-row">
    <div class="field">
        <label class="field__label" for="ws-crews">{{ __('work_sites.fields.crews') }} *</label>
        <input class="field__input" type="number" id="ws-crews" min="1" wire:model.live.debounce.300ms="form.crews">
        @error('form.crews') <p class="field__error">{{ $message }}</p> @enderror
    </div>
    @if ($canChangeStatus)
        <div class="field">
            <label class="field__label" for="ws-status">{{ __('work_sites.fields.status') }}</label>
            <select class="field__input" id="ws-status" wire:model.live="form.status">
                @foreach ($statuses as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </select>
        </div>
    @endif
</div>

@if ($overrun)
    <div class="field-warning" role="alert">
        ⚠ <b>{{ __('work_sites.capacity_exceeded') }}</b> — {{ __('work_sites.capacity_warning', ['peak' => $overrun['peak'], 'capacity' => $overrun['capacity'], 'period' => $overrun['first']->eq($overrun['last']) ? $overrun['first']->translatedFormat('d M') : $overrun['first']->translatedFormat('d M').' → '.$overrun['last']->translatedFormat('d M')]) }}
    </div>
@endif

<div class="field">
    <label class="field__label" for="ws-manager">{{ __('work_sites.fields.manager') }}</label>
    <input class="field__input" id="ws-manager" wire:model="form.manager">
</div>

<div class="field">
    <label class="field__label" for="ws-notes">{{ __('work_sites.fields.notes') }}</label>
    <textarea class="field__input" id="ws-notes" rows="3" wire:model="form.notes"></textarea>
</div>
