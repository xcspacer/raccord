<div class="settings-page settings-page--list">
    <p class="page-hint">{{ __('companies.hint') }}</p>

    <form wire:submit="save" class="settings-card" novalidate>
        <h2 class="settings-card__title">{{ $editingId ? __('companies.edit_title') : __('companies.add_title') }}</h2>

        <div class="field">
            <label class="field__label" for="company-name">{{ __('companies.fields.name') }} *</label>
            <input class="field__input" id="company-name" wire:model="name" maxlength="120">
            @error('name') <p class="field__error" role="alert">{{ $message }}</p> @enderror
        </div>

        <div class="settings-logo">
            @if ($logo?->isPreviewable())
                <img class="settings-logo__preview" src="{{ $logo->temporaryUrl() }}" alt="">
            @elseif ($current?->logo_file_id)
                <img class="settings-logo__preview" src="{{ route('companies.logo', [$current, 'v' => $current->logo_file_id]) }}" alt="">
            @else
                <span class="settings-logo__empty">{{ __('companies.platform_logo') }}</span>
            @endif

            <div class="settings-logo__actions">
                <span class="field__label">{{ __('companies.fields.logo') }}</span>
                <label class="file-picker">
                    <input class="file-picker__input" type="file" wire:model="logo" accept="image/png,image/jpeg,image/webp">
                    <span class="btn btn--ghost">{{ __('companies.choose_file') }}</span>
                    <span class="file-picker__name">{{ $logo?->getClientOriginalName() ?? __('companies.no_file') }}</span>
                </label>
                <p class="field__hint">{{ __('companies.logo_hint') }}</p>
                @error('logo') <p class="field__error" role="alert">{{ $message }}</p> @enderror

                @if ($current?->logo_file_id && ! $logo)
                    <button type="button" class="btn btn--ghost btn--danger" wire:click="removeLogo({{ $current->id }})" wire:confirm="{{ __('companies.confirm_remove_logo') }}">{{ __('companies.remove_logo') }}</button>
                @endif
            </div>
        </div>

        <div class="form-actions">
            @if ($editingId)
                <button type="button" class="btn btn--ghost" wire:click="cancel">{{ __('companies.cancel') }}</button>
            @endif
            <button type="submit" class="btn btn--primary">{{ $editingId ? __('companies.save') : __('companies.add') }}</button>
        </div>
    </form>

    <section class="settings-card">
        <h2 class="settings-card__title">{{ __('companies.list_title') }}</h2>
        @error('delete') <p class="field__error" role="alert">{{ $message }}</p> @enderror

        @forelse ($companies as $company)
            <div class="holiday-row" wire:key="company-{{ $company->id }}">
                <span class="company-row__logo">
                    @if ($company->logo_file_id)
                        <img class="company-row__image" src="{{ route('companies.logo', [$company, 'v' => $company->logo_file_id]) }}" alt="{{ $company->name }}" loading="lazy">
                    @else
                        <span class="company-row__none">{{ __('companies.platform_logo') }}</span>
                    @endif
                </span>
                <span class="holiday-row__name">{{ $company->name }}</span>
                <span class="holiday-row__rule">{{ trans_choice('companies.usage', $company->users_count, ['count' => $company->users_count]) }}</span>
                <button type="button" class="btn btn--ghost btn--small" wire:click="edit({{ $company->id }})">{{ __('companies.edit') }}</button>
                <button type="button" class="chip-delete" wire:click="delete({{ $company->id }})" wire:confirm="{{ __('companies.confirm_delete', ['name' => $company->name]) }}" title="{{ __('companies.delete') }}" aria-label="{{ __('companies.delete') }}">✕</button>
            </div>
        @empty
            <div class="empty-state">{{ __('companies.empty') }}</div>
        @endforelse
    </section>
</div>
