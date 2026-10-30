<div class="billing__card">
    <p class="billing__card-title">{{ __('billing.settings.clients_title') }}</p>
    <p class="billing__muted billing__settings-note">{{ __('billing.settings.clients_hint') }}</p>

    <div class="billing__chips billing__settings-list">
        @foreach ($clients as $client)
            <button type="button" @class(['billing__chip', 'billing__chip--button', 'is-on' => $editingId === $client->id, 'is-off' => ! $client->is_active]) wire:click="edit({{ $client->id }})" wire:key="client-chip-{{ $client->id }}">{{ $client->name }} · {{ $client->form_type->label() }}</button>
        @endforeach
        <button type="button" class="billing__button billing__button--small" wire:click="cancel">+ {{ __('billing.settings.new_client') }}</button>
    </div>

    <form class="billing__settings-form" wire:submit="save">
        <p class="billing__settings-subtitle">{{ $editing ? __('billing.settings.edit_client', ['name' => $editing->name]) : __('billing.settings.new_client') }}</p>
        <div class="billing__grid-auto">
            <div>
                <label class="billing__label" for="client-name">{{ __('billing.settings.name') }}</label>
                <input class="billing__input" id="client-name" wire:model="name" maxlength="120">
                @error('name') <p class="billing__error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="billing__label" for="client-sheet">{{ __('billing.settings.sheet_label') }}</label>
                <input class="billing__input" id="client-sheet" wire:model="sheetLabel" maxlength="31">
                @error('sheetLabel') <p class="billing__error">{{ $message }}</p> @enderror
            </div>
            @if ($formType !== 'day_rate_pair')
                <div>
                    <label class="billing__label" for="client-works-sheet">{{ __('billing.settings.works_sheet_label') }}</label>
                    <input class="billing__input" id="client-works-sheet" wire:model="worksSheetLabel" maxlength="31" placeholder="{{ __('billing.export.sheets.works', ['label' => $sheetLabel]) }}">
                    @error('worksSheetLabel') <p class="billing__error">{{ $message }}</p> @enderror
                </div>
            @endif
            <div>
                <label class="billing__label" for="client-form">{{ __('billing.settings.form_type') }}</label>
                <select class="billing__input" id="client-form" wire:model.live="formType">
                    @foreach (\App\Enums\BillingFormType::cases() as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="billing__label" for="client-order">{{ __('billing.settings.order') }}</label>
                <input class="billing__input" type="number" min="0" max="999" id="client-order" wire:model="sortOrder">
            </div>
        </div>
        <div class="billing__grid-auto">
            <div>
                <label class="billing__label" for="client-pattern">{{ __('billing.settings.ticket_pattern') }}</label>
                <input class="billing__input" id="client-pattern" wire:model="ticketPattern" maxlength="160" placeholder="^C\d{8}$">
                @error('ticketPattern') <p class="billing__error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="billing__label" for="client-hint">{{ __('billing.settings.ticket_hint') }}</label>
                <input class="billing__input" id="client-hint" wire:model="ticketHint" maxlength="160">
            </div>
            <div>
                <label class="billing__label" for="client-example">{{ __('billing.settings.ticket_example') }}</label>
                <input class="billing__input" id="client-example" wire:model="ticketExample" maxlength="40">
            </div>
        </div>
        <div class="billing__settings-checks">
            @if ($formType !== 'day_rate_pair')
                <label class="billing__check"><input type="checkbox" wire:model="hasBreakdown"> {{ __('billing.settings.has_breakdown') }}</label>
                <label class="billing__check"><input type="checkbox" wire:model="hasWorks"> {{ __('billing.settings.has_works') }}</label>
            @endif
            @if ($formType === 'level_schedule')
                <label class="billing__check"><input type="checkbox" wire:model="hasTravelFee"> {{ __('billing.settings.has_travel_fee') }}</label>
            @endif
            <label class="billing__check"><input type="checkbox" wire:model="isActive"> {{ __('billing.settings.active') }}</label>
        </div>
        <div class="billing__actions">
            <button type="submit" class="billing__button">✓ {{ __('billing.settings.save') }}</button>
        </div>
    </form>

    @if ($editing && in_array($editing->form_type, [\App\Enums\BillingFormType::TypeRate, \App\Enums\BillingFormType::LevelSchedule, \App\Enums\BillingFormType::DayRatePair], true))
        <div class="billing__settings-section">
            <p class="billing__settings-subtitle">{{ $editing->form_type === \App\Enums\BillingFormType::DayRatePair ? __('billing.settings.parts') : __('billing.settings.types') }}</p>
            <div class="billing__chips">
                @foreach ($editing->interventionTypes as $type)
                    <span class="billing__chip" wire:key="type-{{ $type->id }}">{{ $type->label }}@if ($type->short_label) ({{ $type->short_label }})@endif @if ($type->sheet_label)· {{ __('billing.settings.sheet_badge', ['name' => $type->sheet_label]) }} @endif @unless ($type->is_billable)· {{ $editing->form_type === \App\Enums\BillingFormType::DayRatePair ? __('billing.settings.on_quote') : __('billing.settings.not_billable') }}@endunless <button type="button" wire:click="removeType({{ $type->id }})" aria-label="{{ __('billing.form.remove_named', ['name' => $type->label]) }}">✕</button></span>
                @endforeach
            </div>
            <div class="billing__row billing__settings-add">
                <input class="billing__input" wire:model="typeLabel" maxlength="80" placeholder="{{ __('billing.settings.type_label') }}" aria-label="{{ __('billing.settings.type_label') }}">
                <input class="billing__input" wire:model="typeShortLabel" maxlength="40" placeholder="{{ __('billing.settings.type_short') }}" aria-label="{{ __('billing.settings.type_short') }}">
                @if ($editing->form_type === \App\Enums\BillingFormType::DayRatePair)
                    <input class="billing__input" wire:model="typeSheetLabel" maxlength="31" placeholder="{{ __('billing.settings.type_sheet') }}" aria-label="{{ __('billing.settings.type_sheet') }}">
                @endif
                <label class="billing__check"><input type="checkbox" wire:model="typeBillable"> {{ $editing->form_type === \App\Enums\BillingFormType::DayRatePair ? __('billing.settings.day_rate_part') : __('billing.settings.billable') }}</label>
                <button type="button" class="billing__button" wire:click="addType">+ {{ __('billing.form.add') }}</button>
            </div>
            @error('typeLabel') <p class="billing__error">{{ $message }}</p> @enderror
            @error('typeSheetLabel') <p class="billing__error">{{ $message }}</p> @enderror
        </div>
    @endif

    <div class="billing__settings-section">
        <p class="billing__settings-subtitle">{{ __('billing.settings.articles') }}</p>
        <div class="billing__row billing__settings-add">
            <input class="billing__input" wire:model="articleCode" maxlength="40" placeholder="{{ __('billing.settings.article_code') }}" aria-label="{{ __('billing.settings.article_code') }}">
            <input class="billing__input billing__input--grow2" wire:model="articleLabel" maxlength="255" placeholder="{{ __('billing.settings.article_label') }}" aria-label="{{ __('billing.settings.article_label') }}">
            <input class="billing__input" wire:model="articleUnit" maxlength="12" placeholder="{{ __('billing.settings.article_unit') }}" aria-label="{{ __('billing.settings.article_unit') }}">
            <label class="billing__check"><input type="checkbox" wire:model="articleCoefficient"> {{ __('billing.settings.article_coefficient') }}</label>
            <button type="button" class="billing__button" wire:click="addArticle">+ {{ __('billing.form.add') }}</button>
        </div>
        @error('articleCode') <p class="billing__error">{{ $message }}</p> @enderror
        @error('articleLabel') <p class="billing__error">{{ $message }}</p> @enderror
        @error('articleUnit') <p class="billing__error">{{ $message }}</p> @enderror
        <div class="billing__chips">
            @foreach ($articles as $article)
                <button type="button" @class(['billing__chip', 'billing__chip--button', 'is-off' => ! $article->is_active]) wire:click="toggleArticle({{ $article->id }})" wire:key="article-{{ $article->id }}" title="{{ $article->is_active ? __('billing.settings.article_off') : __('billing.settings.article_on') }}">{{ $article->code }} — {{ $article->label }} ({{ $article->unit }}){{ $article->is_coefficient ? ' · '.__('billing.settings.coefficient_badge') : '' }}</button>
            @endforeach
        </div>
    </div>
</div>
