@inject('rates', 'App\Billing\BillingRates')

@if ($works && $form === \App\Enums\BillingFormType::DayRatePair)
    <div class="billing__block">
        <label class="billing__label">{{ __('billing.form.part') }}</label>
        <div class="billing__choices billing__choices--wide">
            @foreach ($types as $option)
                @include('livewire.billing.partials.choice', ['name' => 'billing-part', 'value' => $option->id, 'model' => 'typeId', 'checked' => (string) $option->id === $typeId, 'label' => $option->label])
            @endforeach
        </div>
        @if ($chosenType?->is_billable)
            <p class="billing__hint">{{ __('billing.notes.day_rate') }}</p>
        @elseif ($chosenType)
            <div class="billing__block">@include('livewire.billing.partials.times')</div>
            <p class="billing__hint">{{ __('billing.notes.rework_quote', ['client' => $client->name]) }}</p>
        @endif
    </div>
@elseif (! $works)
    @switch($form)
        @case(\App\Enums\BillingFormType::TypeRate)
            <div class="billing__block">
                <label class="billing__label">{{ __('billing.form.intervention_type') }}</label>
                <div class="billing__choices">
                    @foreach ($types as $option)
                        @include('livewire.billing.partials.choice', ['name' => 'billing-type', 'value' => $option->id, 'model' => 'typeId', 'checked' => (string) $option->id === $typeId, 'label' => $option->label])
                    @endforeach
                </div>
                @if ($chosenType && ! $chosenType->is_billable)
                    <p class="billing__hint">{{ __('billing.notes.not_billed') }}</p>
                @endif
            </div>
            @break
        @case(\App\Enums\BillingFormType::LevelSchedule)
            <div class="billing__block">
                <label class="billing__label">{{ __('billing.form.breakdown_type') }}</label>
                <div class="billing__choices">
                    @foreach ($types as $option)
                        @include('livewire.billing.partials.choice', ['name' => 'billing-type', 'value' => $option->id, 'model' => 'typeId', 'checked' => (string) $option->id === $typeId, 'label' => $option->label])
                    @endforeach
                </div>
                @if ($chosenType?->is_billable)
                    <label class="billing__label billing__label--spaced">{{ __('billing.form.level') }}</label>
                    <div class="billing__choices">
                        @foreach ([1, 2, 3] as $option)
                            @include('livewire.billing.partials.choice', ['name' => 'billing-level', 'value' => $option, 'model' => 'level', 'checked' => (string) $option === $level, 'label' => __('billing.form.level_n', ['level' => $option])])
                        @endforeach
                    </div>
                    <label class="billing__label billing__label--spaced">{{ __('billing.form.schedule') }}</label>
                    <div class="billing__choices">
                        @foreach (\App\Enums\BillingSchedule::cases() as $option)
                            @include('livewire.billing.partials.choice', ['name' => 'billing-schedule', 'value' => $option->value, 'model' => 'schedule', 'checked' => $option->value === $schedule, 'label' => $option->label()])
                        @endforeach
                    </div>
                @endif
            </div>
            @break
        @case(\App\Enums\BillingFormType::Hourly)
            <div class="billing__block">
                <label class="billing__label" for="billing-operator">{{ __('billing.form.operator') }}</label>
                <select class="billing__input billing__input--wide" id="billing-operator" wire:model="operatorId">
                    <option value="">{{ __('billing.form.operator_pick') }}</option>
                    @foreach ($operators as $operator)
                        <option value="{{ $operator->id }}">{{ $operator->name }}</option>
                    @endforeach
                </select>
                @include('livewire.billing.partials.times')
                <p class="billing__hint">{{ __('billing.notes.hourly') }}</p>
            </div>
            @break
        @case(\App\Enums\BillingFormType::TicketFlat)
            <div class="billing__block">
                @include('livewire.billing.partials.times')
                <p class="billing__hint">{{ __('billing.notes.flat_times') }}</p>
                <label class="billing__label billing__label--spaced">{{ __('billing.form.ticket_status') }}</label>
                <div class="billing__choices">
                    @foreach (\App\Enums\BillingClosureStatus::cases() as $option)
                        @include('livewire.billing.partials.choice', ['name' => 'billing-closure', 'value' => $option->value, 'model' => 'closure', 'checked' => $option->value === $closure, 'label' => $option->label()])
                    @endforeach
                </div>
                <p class="billing__hint">{{ __('billing.notes.flat_rate', ['amount' => \App\Billing\EntryBadges::money($rates->get($client, \App\Billing\BillingRates::TICKET))]) }}</p>
            </div>
            @break
        @case(\App\Enums\BillingFormType::Quote)
            <div class="billing__block">
                @include('livewire.billing.partials.times')
                <p class="billing__hint">{{ __('billing.notes.quote') }}</p>
            </div>
            @break
    @endswitch
@endif
