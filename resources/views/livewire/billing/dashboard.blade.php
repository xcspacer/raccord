<div wire:poll.5s.visible>
    <div class="billing__heading">
        <p class="billing__eyebrow">{{ __('billing.dashboard.eyebrow') }}</p>
        <h2 class="billing__title billing__title--large">{{ __('billing.dashboard.title') }}</h2>
    </div>

    @if ($reminders !== [])
        <div class="billing__banner billing__banner--accent">
            <p class="billing__banner-title">{{ __('billing.dashboard.reminders_title', ['count' => count($reminders)]) }}</p>
            @foreach ($reminders as $reminder)
                <p class="billing__banner-line">{!! __('billing.dashboard.reminder', ['name' => '<strong>'.e($reminder['partner']->name).'</strong>', 'client' => '<strong>'.e($reminder['entry']->billingClient->name).'</strong>', 'date' => $reminder['entry']->work_date->format('d/m/Y'), 'ticket' => e($reminder['entry']->ticket), 'by' => e($reminder['entry']->user->name)]) !!}</p>
            @endforeach
        </div>
    @endif

    <div class="billing__grid-auto billing__filters">
        <div>
            <label class="billing__label" for="dashboard-month">{{ __('billing.expenses.month') }}</label>
            <input class="billing__input" type="month" id="dashboard-month" wire:model.live="month" @disabled($day !== '')>
        </div>
        <div>
            <label class="billing__label" for="dashboard-day">{{ __('billing.dashboard.day') }} @if ($day !== '')<button type="button" class="billing__link" wire:click="clearDay" title="{{ __('billing.dashboard.clear_day_title') }}">{{ __('billing.dashboard.clear_day') }}</button>@endif</label>
            <input class="billing__input" type="date" id="dashboard-day" wire:model.live="day">
        </div>
        <div>
            <label class="billing__label" for="dashboard-technician">{{ __('billing.detail.technician') }}</label>
            <select class="billing__input" id="dashboard-technician" wire:model.live="technician">
                <option value="">{{ __('billing.expenses.all') }}</option>
                @foreach ($technicians as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="billing__label" for="dashboard-type">{{ __('billing.dashboard.type') }}</label>
            <select class="billing__input" id="dashboard-type" wire:model.live="type">
                <option value="">{{ __('billing.expenses.all') }}</option>
                @foreach (\App\Enums\BillingEntryType::cases() as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="billing__label" for="dashboard-client">{{ __('billing.dashboard.client') }}</label>
            <select class="billing__input" id="dashboard-client" wire:model.live="client">
                <option value="">{{ __('billing.expenses.all') }}</option>
                @foreach ($clients as $option)
                    <option value="{{ $option->id }}">{{ $option->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <p class="billing__section-title billing__counters">
        {{ __('billing.dashboard.count', ['count' => $filtered->count()]) }}
        @if ($duplicates !== [])<button type="button" class="billing__link billing__link--danger" wire:click="toggle('duplicates')" title="{{ $duplicatesOnly ? __('billing.dashboard.show_all') : __('billing.dashboard.only_duplicates_title') }}">· {{ __('billing.dashboard.duplicates', ['count' => count($duplicates)]) }}</button>@endif
        @if ($crew !== [])<button type="button" class="billing__link billing__link--danger" wire:click="toggle('crew')" title="{{ $crewOnly ? __('billing.dashboard.show_all') : __('billing.dashboard.only_crew_title') }}">· {{ __('billing.dashboard.crew', ['count' => count($crew)]) }}</button>@endif
        @if ($content !== [])<button type="button" class="billing__link billing__link--danger" wire:click="toggle('content')" title="{{ $contentOnly ? __('billing.dashboard.show_all') : __('billing.dashboard.only_content_title') }}">· {{ __('billing.dashboard.content', ['count' => count($content)]) }}</button>@endif
        @foreach (['duplicates' => $duplicatesOnly, 'content' => $contentOnly, 'crew' => $crewOnly] as $filterName => $on)
            @if ($on)
                <span class="billing__chip">{{ __('billing.dashboard.only.'.$filterName) }} <button type="button" wire:click="toggle('{{ $filterName }}')" title="{{ __('billing.dashboard.show_all') }}" aria-label="{{ __('billing.dashboard.show_all') }}">✕</button></span>
            @endif
        @endforeach
    </p>

    @if ($isAdministrator)
        <div class="billing__panel-buttons">
            @foreach (['people', 'rates', 'clients'] as $name)
                <button type="button" @class(['billing__button', 'is-on' => $panel === $name]) wire:click="togglePanel('{{ $name }}')">{{ $panel === $name ? __('billing.settings.hide.'.$name) : __('billing.settings.show.'.$name) }}</button>
            @endforeach
            <a class="billing__button" href="{{ route('billing.exports.global', ['month' => $month]) }}" download>{{ __('billing.dashboard.export_global') }}</a>
            <a class="billing__button" href="{{ route('billing.exports.technicians', ['month' => $month]) }}" download>{{ __('billing.dashboard.export_technicians') }}</a>
        </div>
        @if ($panel === 'people')
            <livewire:billing.technician-defaults :key="'panel-people'" />
        @elseif ($panel === 'rates')
            <livewire:billing.rate-settings :key="'panel-rates'" />
        @elseif ($panel === 'clients')
            <livewire:billing.client-settings :key="'panel-clients'" />
        @endif

        <div class="billing__total">
            <span>{{ __('billing.dashboard.total', ['period' => $period]) }}</span>
            <strong>{{ \App\Billing\EntryBadges::money($grandTotal) }}</strong>
        </div>
        @if ($approvedTotal > 0 || $pendingTotal > 0)
            <div class="billing__total billing__total--expenses">
                <span>{{ __('billing.dashboard.expenses_total', ['period' => $period]) }}@if ($pendingTotal > 0) · {{ __('billing.dashboard.expenses_pending', ['amount' => \App\Billing\EntryBadges::money($pendingTotal)]) }}@endif</span>
                <strong>{{ \App\Billing\EntryBadges::money($approvedTotal) }}</strong>
            </div>
        @endif
    @endif

    @if ($technicians !== [])
        <div class="billing__row billing__card-tools">
            <input class="billing__input billing__input--small" type="text" wire:model.live.debounce.250ms="technicianSearch" placeholder="{{ __('billing.dashboard.search_technician') }}" aria-label="{{ __('billing.dashboard.search_technician') }}">
            <label class="billing__label billing__label--inline" for="dashboard-sort">{{ __('billing.dashboard.sort') }}</label>
            <select class="billing__input billing__input--small billing__input--auto" id="dashboard-sort" wire:model.live="technicianSort">
                <option value="name">{{ __('billing.dashboard.sort_name') }}</option>
                <option value="tickets_desc">{{ __('billing.dashboard.sort_desc') }}</option>
                <option value="tickets_asc">{{ __('billing.dashboard.sort_asc') }}</option>
            </select>
        </div>
        <p class="billing__muted billing__card-hint">{{ $technician !== '' ? __('billing.dashboard.card_detail', ['name' => $technicians[(int) $technician] ?? '']) : __('billing.dashboard.card_hint') }}</p>
        @if ($cards === [])
            <p class="billing__muted">{{ __('billing.dashboard.no_technician', ['search' => $technicianSearch]) }}</p>
        @endif
        <div class="billing__cards">
            @foreach ($cards as $card)
                <button type="button" @class(['billing__tech-card', 'is-on' => (string) $card['id'] === $technician]) wire:click="chooseTechnician({{ $card['id'] }})" wire:key="card-{{ $card['id'] }}" title="{{ (string) $card['id'] === $technician ? __('billing.dashboard.card_all') : __('billing.dashboard.card_see', ['name' => $card['name']]) }}">
                    <span class="billing__tech-name">{{ $card['name'] }} <span class="billing__muted">· {{ __('billing.dashboard.tickets', ['count' => $card['breakdowns'] + $card['works']]) }}</span></span>
                    <span class="billing__tech-line">{{ __('billing.dashboard.split', ['breakdowns' => $card['breakdowns'], 'works' => $card['works']]) }}</span>
                    @if ($isAdministrator)
                        <span class="billing__tech-amount">{{ \App\Billing\EntryBadges::money($card['amount']) }}</span>
                        @if ($card['approved'] > 0 || $card['pending'] > 0)
                            <span class="billing__tech-expenses">{{ __('billing.dashboard.card_expenses', ['amount' => \App\Billing\EntryBadges::money($card['approved'])]) }}@if ($card['pending'] > 0) {{ __('billing.dashboard.card_pending', ['amount' => \App\Billing\EntryBadges::money($card['pending'])]) }}@endif</span>
                        @endif
                    @endif
                </button>
            @endforeach
        </div>
    @endif

    @if ($visible->isEmpty())
        <p class="billing__muted">{{ $duplicatesOnly ? __('billing.dashboard.empty_duplicates') : ($contentOnly ? __('billing.dashboard.empty_content') : ($crewOnly ? __('billing.dashboard.empty_crew') : __('billing.dashboard.empty'))) }}</p>
    @else
        <div class="billing__entries">
            @foreach ($visible as $entry)
                <div wire:key="dashboard-entry-{{ $entry->id }}">
                    @include('livewire.billing.partials.entry-row', [
                        'entry' => $entry,
                        'showTech' => true,
                        'mine' => false,
                        'canEditOthers' => $isAdministrator,
                        'ticketEditable' => true,
                        'amount' => $isAdministrator ? ($shares[$entry->id] ?? 0.0) : null,
                        'warnings' => array_values(array_filter([isset($duplicates[$entry->id]) ? __('billing.warnings.duplicate') : null, in_array($entry->id, $crew, true) ? __('billing.warnings.crew') : null, in_array($entry->id, $content, true) ? __('billing.warnings.content') : null])),
                    ])
                </div>
            @endforeach
        </div>
    @endif

    @if ($editEntryId)
        <div class="billing__overlay billing__overlay--top" wire:click.self="closeEdit">
            <div class="billing__modal billing__modal--form" role="dialog" aria-modal="true">
                <div class="billing__card-head">
                    <p class="billing__modal-title">{{ __('billing.dashboard.edit_title', ['name' => $editName]) }}</p>
                    <button type="button" class="billing__icon-button" wire:click="closeEdit" aria-label="{{ __('billing.detail.close') }}">✕</button>
                </div>
                <livewire:billing.my-entries :edit-entry-id="$editEntryId" :key="'edit-'.$editEntryId" />
            </div>
        </div>
    @endif

    @if ($detail)
        @include('livewire.billing.partials.entry-detail', ['entry' => $detail, 'amount' => $isAdministrator ? ($shares[$detail->id] ?? null) : null])
    @endif
</div>
