<div class="settings-page settings-page--list">
    <p class="page-hint">{{ __('agencies.hint') }}</p>

    <form wire:submit="save" class="settings-card" novalidate>
        <h2 class="settings-card__title">{{ $editingId ? __('agencies.edit_title') : __('agencies.add_title') }}</h2>

        <div class="field">
            <label class="field__label" for="agency-name">{{ __('agencies.fields.name') }} *</label>
            <input class="field__input" id="agency-name" wire:model="name" maxlength="80">
            @error('name') <p class="field__error" role="alert">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label class="field__label" for="agency-mode">{{ __('agencies.fields.mode') }}</label>
            <select class="field__input" id="agency-mode" wire:model.live="mode">
                @foreach ($modes as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </select>
            <p class="field__hint">{{ __('agencies.mode_hint.'.$mode) }}</p>
            @error('mode') <p class="field__error" role="alert">{{ $message }}</p> @enderror
        </div>

        @if ($mode === 'detailed')
            <div class="field">
                <label class="field__label" for="agency-capacity">{{ __('agencies.fields.capacity') }}</label>
                <input class="field__input field__input--narrow" type="number" min="1" max="255" id="agency-capacity" wire:model="capacity">
                <p class="field__hint">{{ __('agencies.capacity_hint') }}</p>
                @error('capacity') <p class="field__error" role="alert">{{ $message }}</p> @enderror
            </div>
        @endif

        <div class="field">
            <label class="field__label" for="agency-client">{{ __('agencies.fields.default_client') }}</label>
            <input class="field__input" id="agency-client" list="agency-clients" wire:model="defaultClient" autocomplete="off">
            <datalist id="agency-clients">
                @foreach ($clients as $name)
                    <option value="{{ $name }}"></option>
                @endforeach
            </datalist>
            <p class="field__hint">{{ __('agencies.default_client_hint') }}</p>
        </div>

        <div class="form-actions">
            @if ($editingId)
                <button type="button" class="btn btn--ghost" wire:click="cancel">{{ __('agencies.cancel') }}</button>
            @endif
            <button type="submit" class="btn btn--primary">{{ $editingId ? __('agencies.save') : __('agencies.add') }}</button>
        </div>
    </form>

    <section class="settings-card">
        <h2 class="settings-card__title">{{ __('agencies.list_title') }}</h2>
        @error('delete') <p class="field__error" role="alert">{{ $message }}</p> @enderror

        @foreach ($agencies as $agency)
            <div class="holiday-row" wire:key="agency-{{ $agency->id }}">
                <span class="agency-row__order">
                    <button type="button" class="chip-delete" wire:click="move({{ $agency->id }}, -1)" aria-label="{{ __('agencies.move_up') }}" @disabled($loop->first)>↑</button>
                    <button type="button" class="chip-delete" wire:click="move({{ $agency->id }}, 1)" aria-label="{{ __('agencies.move_down') }}" @disabled($loop->last)>↓</button>
                </span>
                <span class="holiday-row__name">
                    {{ $agency->name }}
                    <small class="agency-row__meta">{{ $agency->planning_mode->label() }}@if ($agency->planning_mode === \App\Enums\PlanningMode::Detailed) · {{ __('agencies.capacity_short', ['count' => $agency->daily_crew_capacity]) }}@endif @if ($agency->defaultClient) · {{ $agency->defaultClient->name }}@endif</small>
                </span>
                <span class="holiday-row__rule">{{ __('agencies.usage', ['users' => $agency->users_count, 'sites' => $agency->work_sites_count]) }}</span>
                <button type="button" class="btn btn--ghost btn--small" wire:click="edit({{ $agency->id }})">{{ __('agencies.edit') }}</button>
                <button type="button" class="chip-delete" wire:click="delete({{ $agency->id }})" wire:confirm="{{ __('agencies.confirm_delete', ['name' => $agency->name]) }}" title="{{ __('agencies.delete') }}" aria-label="{{ __('agencies.delete') }}">✕</button>
            </div>
        @endforeach
    </section>
</div>
