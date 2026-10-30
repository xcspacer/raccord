<div class="settings-page">
    <p class="page-hint">{{ __('platform_settings.hint') }} {{ __('platform_settings.usage_hint') }}</p>

    <form wire:submit="save" class="settings-card" novalidate>
        <h2 class="settings-card__title">{{ __('platform_settings.company') }}</h2>

        <div class="settings-logo">
            @if ($logo?->isPreviewable())
                <img class="settings-logo__preview" src="{{ $logo->temporaryUrl() }}" alt="">
            @elseif ($logoUrl)
                <img class="settings-logo__preview" src="{{ $logoUrl }}" alt="">
            @else
                <span class="settings-logo__empty">{{ __('platform_settings.no_logo') }}</span>
            @endif

            <div class="settings-logo__actions">
                <span class="field__label">{{ __('platform_settings.logo') }}</span>
                <label class="file-picker">
                    <input class="file-picker__input" type="file" wire:model="logo" accept="image/png,image/jpeg,image/webp">
                    <span class="btn btn--ghost">{{ __('platform_settings.choose_file') }}</span>
                    <span class="file-picker__name">{{ $logo?->getClientOriginalName() ?? __('platform_settings.no_file') }}</span>
                </label>
                <p class="field__hint">{{ __('platform_settings.logo_hint') }}</p>
                @error('logo') <p class="field__error" role="alert">{{ $message }}</p> @enderror

                @if ($logoUrl && ! $logo)
                    <button type="button" class="btn btn--ghost btn--danger" wire:click="removeLogo" wire:confirm="{{ __('platform_settings.confirm_remove_logo') }}">{{ __('platform_settings.remove_logo') }}</button>
                @endif
            </div>
        </div>

        <div class="settings-grid">
            @foreach ([
                'name' => 'text', 'legalName' => 'text', 'email' => 'email', 'phone' => 'tel',
                'legalIdentifier' => 'text', 'website' => 'url', 'mailFromName' => 'text', 'mailFromAddress' => 'email', 'expenseReportEmail' => 'email',
            ] as $field => $type)
                <div class="field">
                    <label class="field__label" for="{{ $field }}">{{ __('platform_settings.fields.'.$field) }}</label>
                    <input class="field__input" type="{{ $type }}" id="{{ $field }}" wire:model="{{ $field }}" @if ($field === 'name') placeholder="{{ config('app.name') }}" @endif>
                    @error($field) <p class="field__error" role="alert">{{ $message }}</p> @enderror
                </div>
            @endforeach
        </div>

        <div class="field">
            <label class="field__label" for="address">{{ __('platform_settings.fields.address') }}</label>
            <textarea class="field__input" id="address" rows="3" wire:model="address"></textarea>
            @error('address') <p class="field__error" role="alert">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label class="field__label" for="documentFooter">{{ __('platform_settings.fields.documentFooter') }}</label>
            <textarea class="field__input" id="documentFooter" rows="3" wire:model="documentFooter"></textarea>
            <p class="field__hint">{{ __('platform_settings.document_footer_hint') }}</p>
            @error('documentFooter') <p class="field__error" role="alert">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label class="field__label" for="vehicleCycleStart">{{ __('platform_settings.vehicle_cycle') }}</label>
            <input class="field__input field__input--narrow" type="month" id="vehicleCycleStart" wire:model="vehicleCycleStart">
            <p class="field__hint">{{ __('platform_settings.vehicle_cycle_hint') }}</p>
            @error('vehicleCycleStart') <p class="field__error" role="alert">{{ $message }}</p> @enderror
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary">{{ __('platform_settings.save') }}</button>
        </div>
    </form>

    @if ($workflowActive)
        <form wire:submit="saveWorkflow" class="settings-card" novalidate>
            <h2 class="settings-card__title">{{ __('platform_settings.design_office_workflow') }}</h2>
            <p class="page-hint">{{ __('platform_settings.design_office_workflow_hint') }}</p>

            <div class="settings-grid">
                <div class="field">
                    <label class="field__label" for="reviewDeadlineMinutes">{{ __('platform_settings.fields.reviewDeadlineMinutes') }}</label>
                    <input class="field__input field__input--narrow" type="number" min="1" max="240" id="reviewDeadlineMinutes" wire:model="reviewDeadlineMinutes">
                    @error('reviewDeadlineMinutes') <p class="field__error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label class="field__label" for="reviewAlertMinutes">{{ __('platform_settings.fields.reviewAlertMinutes') }}</label>
                    <input class="field__input field__input--narrow" type="number" min="1" max="240" id="reviewAlertMinutes" wire:model="reviewAlertMinutes">
                    @error('reviewAlertMinutes') <p class="field__error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label class="field__label" for="maxOpenRequests">{{ __('platform_settings.fields.maxOpenRequests') }}</label>
                    <input class="field__input field__input--narrow" type="number" min="1" max="100" id="maxOpenRequests" wire:model="maxOpenRequests" placeholder="{{ __('platform_settings.no_cap') }}">
                    <p class="field__hint">{{ __('platform_settings.max_open_requests_hint') }}</p>
                    @error('maxOpenRequests') <p class="field__error" role="alert">{{ $message }}</p> @enderror
                </div>
            </div>

            <fieldset class="field settings-folders">
                <legend class="field__label">{{ __('platform_settings.fields.exemptFolders') }}</legend>
                <p class="field__hint">{{ __('platform_settings.exempt_folders_hint') }}</p>
                <div class="settings-folders__list">
                    @foreach ($folders as $folder)
                        <label class="settings-folders__option" wire:key="exempt-{{ $folder->value }}">
                            <input type="checkbox" value="{{ $folder->value }}" wire:model="exemptFolders"> {{ $folder->label() }}
                        </label>
                    @endforeach
                </div>
                @error('exemptFolders.*') <p class="field__error" role="alert">{{ $message }}</p> @enderror
            </fieldset>

            <div class="module-row">
                <span class="module-row__text">
                    <span class="module-row__label">{{ __('platform_settings.fields.aiDraftsEnabled') }}</span>
                    <small>{{ __('platform_settings.ai_drafts_hint') }}</small>
                </span>
                <label class="toggle-switch">
                    <input type="checkbox" id="aiDraftsEnabled" wire:model="aiDraftsEnabled" aria-label="{{ __('platform_settings.fields.aiDraftsEnabled') }}">
                    <span></span>
                </label>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn--primary">{{ __('platform_settings.save') }}</button>
            </div>
        </form>
    @endif

    <section class="settings-card">
        <h2 class="settings-card__title">{{ __('platform_settings.modules') }}</h2>
        <p class="page-hint">{{ __('platform_settings.modules_hint') }}</p>

        @foreach ($modules as $row)
            <div class="module-row" wire:key="module-{{ $row['module']->value }}">
                <span class="module-row__text">
                    <span class="module-row__label">{{ $row['module']->label() }}</span>
                    @if ($row['requires'] !== [])
                        <small>{{ __('platform_settings.requires', ['modules' => collect($row['requires'])->map->label()->join(', ')]) }}</small>
                    @endif
                    @if ($row['switchedOn'] && ! $row['active'])
                        <small class="module-row__warning">{{ __('platform_settings.inactive_dependency') }}</small>
                    @endif
                </span>

                <label class="toggle-switch">
                    <input type="checkbox" @checked($row['switchedOn']) wire:click="toggleModule('{{ $row['module']->value }}')" aria-label="{{ $row['module']->label() }}">
                    <span></span>
                </label>
            </div>
        @endforeach
    </section>
</div>
