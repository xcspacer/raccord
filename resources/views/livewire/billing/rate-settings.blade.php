@php($field = fn (?int $client, string $key) => \App\Livewire\Billing\RateSettings::field($client, $key))
<div class="billing__card">
    @forelse ($clients as $client)
        @php($types = $client->interventionTypes->where('is_billable', true))
        @if ($client->form_type !== \App\Enums\BillingFormType::Quote)
            <div class="billing__settings-section" wire:key="rates-{{ $client->id }}">
                <p class="billing__card-title">{{ __('billing.settings.grid_title', ['client' => $client->name]) }}</p>
                <p class="billing__muted billing__settings-note">{{ __('billing.settings.private') }}</p>
                @switch($client->form_type)
                    @case(\App\Enums\BillingFormType::LevelSchedule)
                        @foreach (\App\Enums\BillingSchedule::cases() as $schedule)
                            <p class="billing__settings-subtitle">{{ $schedule->label() }}</p>
                            <div class="billing__table-wrap">
                                <table class="billing__table">
                                    <thead><tr><th>{{ __('billing.settings.level') }}</th>@foreach ($types as $type)<th>{{ $type->label }}</th>@endforeach</tr></thead>
                                    <tbody>
                                        @foreach ([1, 2, 3] as $level)
                                            <tr>
                                                <td>{{ __('billing.form.level_n', ['level' => $level]) }}</td>
                                                @foreach ($types as $type)
                                                    <td>@include('livewire.billing.partials.rate-input', ['name' => $field($client->id, \App\Billing\BillingRates::gridKey($schedule, $level, $type)), 'label' => $type->label.' — '.__('billing.form.level_n', ['level' => $level])])</td>
                                                @endforeach
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endforeach
                        @if ($client->has_travel_fee)
                            <div class="billing__grid-auto">
                                @foreach (\App\Enums\BillingSchedule::cases() as $schedule)
                                    <div>
                                        <label class="billing__label">{{ __('billing.settings.travel', ['schedule' => $schedule->label()]) }}</label>
                                        @include('livewire.billing.partials.rate-input', ['name' => $field($client->id, \App\Billing\BillingRates::travelKey($schedule)), 'label' => __('billing.settings.travel', ['schedule' => $schedule->label()])])
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        @break
                    @case(\App\Enums\BillingFormType::Hourly)
                        <div class="billing__grid-auto">
                            @foreach (\App\Enums\BillingSchedule::cases() as $schedule)
                                <div>
                                    <label class="billing__label">{{ __('billing.settings.hourly.'.$schedule->value) }}</label>
                                    @include('livewire.billing.partials.rate-input', ['name' => $field($client->id, \App\Billing\BillingRates::hourKey($schedule)), 'label' => __('billing.settings.hourly.'.$schedule->value)])
                                </div>
                            @endforeach
                        </div>
                        @break
                    @case(\App\Enums\BillingFormType::TicketFlat)
                        <div class="billing__grid-auto">
                            <div>
                                <label class="billing__label">{{ __('billing.settings.ticket_price') }}</label>
                                @include('livewire.billing.partials.rate-input', ['name' => $field($client->id, \App\Billing\BillingRates::TICKET), 'label' => __('billing.settings.ticket_price')])
                            </div>
                        </div>
                        @break
                    @default
                        <div class="billing__grid-auto">
                            @foreach ($types as $type)
                                <div>
                                    <label class="billing__label">{{ $client->form_type === \App\Enums\BillingFormType::DayRatePair ? __('billing.settings.day_price', ['type' => $type->label]) : $type->label }}</label>
                                    @include('livewire.billing.partials.rate-input', ['name' => $field($client->id, \App\Billing\BillingRates::typeKey($type)), 'label' => $type->label])
                                </div>
                            @endforeach
                        </div>
                @endswitch
            </div>
        @endif
    @empty
        <p class="billing__muted">{{ __('billing.settings.no_clients') }}</p>
    @endforelse

    <div class="billing__settings-section">
        <p class="billing__card-title">{{ __('billing.settings.operators') }}</p>
        <div class="billing__row">
            <input class="billing__input" type="text" wire:model="operatorName" wire:keydown.enter.prevent="addOperator" maxlength="80" placeholder="{{ __('billing.settings.operator_name') }}" aria-label="{{ __('billing.settings.operator_name') }}">
            <button type="button" class="billing__button" wire:click="addOperator">+ {{ __('billing.form.add') }}</button>
        </div>
        @if ($operatorError !== '') <p class="billing__error">{{ $operatorError }}</p> @endif
        <div class="billing__chips">
            @foreach ($operators as $operator)
                <span class="billing__chip" wire:key="operator-{{ $operator->id }}">{{ $operator->name }} <button type="button" wire:click="removeOperator({{ $operator->id }})" aria-label="{{ __('billing.form.remove_named', ['name' => $operator->name]) }}">✕</button></span>
            @endforeach
        </div>
    </div>

    <div class="billing__settings-section">
        <p class="billing__card-title">{{ __('billing.settings.works_grid') }}</p>
        <p class="billing__muted billing__settings-note">{{ __('billing.settings.private') }}</p>
        <input class="billing__input billing__input--wide" type="text" wire:model.live.debounce.250ms="articleSearch" placeholder="{{ __('billing.settings.article_search') }}" aria-label="{{ __('billing.settings.article_search') }}">
        <div class="billing__article-prices">
            @foreach ($articles as $article)
                <div class="billing__article-price" wire:key="article-price-{{ $article->id }}">
                    <span class="billing__muted">{{ $article->code }} ({{ $article->unit }})</span>
                    <span>{{ $article->label }}</span>
                    @include('livewire.billing.partials.rate-input', ['name' => $field(null, \App\Billing\BillingRates::articleKey($article)), 'label' => $article->code, 'unit' => $article->is_coefficient ? __('billing.settings.coefficient_unit') : '€'])
                </div>
            @endforeach
        </div>
    </div>
</div>
