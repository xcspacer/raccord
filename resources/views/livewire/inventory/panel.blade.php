<div class="inventory" @if ($reading) wire:poll.3s @endif>
    @if ($vehicle)
        <div class="inventory__vehicle">🚐 {{ $vehicle }}</div>
    @endif

    <div class="inventory__kpis">
        @foreach ($kpis as $key => $value)
            <div @class(['inventory__kpi', 'inventory__kpi--'.$key => $value > 0])>
                <span class="inventory__kpi-value">{{ $value }}</span>
                <span class="inventory__kpi-label">{{ __('inventory.kpi.'.$mode.'.'.$key) }}</span>
            </div>
        @endforeach
    </div>

    @if ($canNote)
        <section class="settings-card inventory__label">
            <h2 class="settings-card__title">{{ __('inventory.label.section_title') }}</h2>
            <p class="inventory__hint">{{ __('inventory.label.section_hint') }}</p>
            <x-ai-notice />
            <div class="inventory__bar">
                <label class="file-picker">
                    <input class="file-picker__input" type="file" wire:model="labelPhoto" accept="image/jpeg,image/png,image/webp" capture="environment">
                    <span class="btn btn--primary">📷 {{ __('inventory.label.take_photo') }}</span>
                </label>
                <label class="file-picker">
                    <input class="file-picker__input" type="file" wire:model="labelPhoto" accept="image/jpeg,image/png,image/webp">
                    <span class="btn btn--ghost">🖼 {{ __('inventory.label.choose_photo') }}</span>
                </label>
                <a class="btn btn--ghost" href="{{ route('equipment-report') }}" target="_blank" rel="noopener">📄 {{ __('inventory.label.report') }}</a>
            </div>
            @error('labelPhoto') <p class="field__error">{{ $message }}</p> @enderror
        </section>
    @endif

    @if ($isManager)
        <div class="inventory__bar">
            <button type="button" class="btn btn--primary" wire:click="newItem">＋ {{ __('inventory.new_equipment') }}</button>
            <button type="button" class="btn btn--ghost" wire:click="openBulk('photos')">📷 {{ __('inventory.bulk.photos_button') }}</button>
            @if ($mode === 'user')
                <button type="button" class="btn btn--ghost" wire:click="openAttestation">📄 {{ __('inventory.attestation.button') }}</button>
            @endif
            <button type="button" class="btn btn--ghost" wire:click="openBulk('import')">📥 {{ __('inventory.bulk.import_button') }}</button>
            <a class="btn btn--ghost" href="{{ route('equipment.export', ['format' => 'excel', 'user' => $userId]) }}">⬇ {{ __('inventory.export_excel') }}</a>
            <a class="btn btn--ghost" href="{{ route('equipment.export', ['format' => 'pdf', 'user' => $userId]) }}">⬇ {{ __('inventory.export_pdf') }}</a>
        </div>
    @endif

    <div class="space__tabs" role="tablist">
        @foreach ($tabs as $name)
            <button type="button" role="tab" @class(['btn', 'btn--ghost', 'is-active' => $tab === $name]) wire:click="setTab('{{ $name }}')">{{ __('inventory.tabs.'.($mode === 'central' ? 'central' : 'user').'.'.$name) }}</button>
        @endforeach
    </div>

    @if ($tab === 'inventory')
        @if ($mode === 'user' && $isManager)
            <section class="settings-card inventory__stock">
                <label class="field__label" for="inventory-stock">{{ __('inventory.stock_available') }}</label>
                @if (count($stock) > 0)
                    <select class="field__input" id="inventory-stock" wire:change="assignFromStock($event.target.value)">
                        <option value="">—</option>
                        @foreach ($stock as $line)
                            <option value="{{ $line->id }}">{{ $line->label }}{{ $line->brand || $line->model ? ' · '.trim($line->brand.' '.$line->model) : '' }}{{ $line->serial ? ' · S/N '.$line->serial : '' }}{{ $line->quantity > 1 ? ' (×'.$line->quantity.')' : '' }}</option>
                        @endforeach
                    </select>
                @else
                    <p class="inventory__hint">{{ __('inventory.no_match') }}</p>
                @endif
            </section>
        @endif

        @if ($toConfirmCount > 0 && $filter !== 'problem')
            <div class="field-warning" role="status">
                ⚠ {{ trans_choice('inventory.to_confirm_banner', $toConfirmCount, ['count' => $toConfirmCount]) }}
                <small>{{ __('inventory.help_confirm') }}</small>
                @if ($isManager)
                    <button type="button" class="btn btn--primary" wire:click="askConfirmAll">✔ {{ __('inventory.confirm_everything') }}</button>
                @endif
            </div>
        @endif

        <input class="field__input inventory__search" type="search" wire:model.live.debounce.200ms="search" placeholder="{{ __('inventory.search') }}" aria-label="{{ __('inventory.search') }}">

        <div class="inventory__filters" role="group">
            @foreach (array_filter(['all', 'to_confirm', 'assigned', $mode === 'central' ? 'stock' : null, 'problem', $hasDeleted ? 'deleted' : null]) as $name)
                <button type="button" @class(['inventory__filter', 'is-active' => $filter === $name]) wire:click="$set('filter', '{{ $name }}')">{{ __('inventory.filters.'.$name) }}</button>
            @endforeach
        </div>

        @forelse ($sections as $section)
            <section class="inventory__section inventory__section--{{ $section['key'] }}" wire:key="section-{{ $section['key'] }}">
                <h3 class="inventory__section-title">{{ __('inventory.sections.'.$section['key']) }} <span class="inventory__count">{{ $section['items']->count() }}</span></h3>
                @foreach ($section['items'] as $item)
                    @include('livewire.inventory.partials.item-row', ['item' => $item, 'showHolder' => $mode === 'central'])
                @endforeach
            </section>
        @empty
            <div class="empty-state">{{ $search !== '' || $filter !== 'all' ? __('inventory.no_match') : __('inventory.empty') }}</div>
        @endforelse
    @elseif ($tab === 'by-user')
        <select class="field__input field__input--narrow" wire:model.live="openUser" aria-label="{{ __('inventory.open_file') }}">
            <option value="">{{ __('inventory.open_file') }}</option>
            @foreach ($people as $person)
                <option value="{{ $person->id }}">{{ $person->name }}</option>
            @endforeach
        </select>

        <div class="inventory__cards">
            @forelse ($cards as $card)
                <a class="inventory__card" href="{{ route('equipment.users.show', ['person' => $card['user']->id]) }}" wire:navigate wire:key="card-{{ $card['user']->id }}">
                    <strong>{{ $card['user']->name }}</strong>
                    <span>{{ trans_choice('inventory.lines_count', $card['lines'], ['count' => $card['lines']]) }}@if ($card['to_confirm'] > 0) · ⚠ {{ trans_choice('inventory.to_confirm_count', $card['to_confirm'], ['count' => $card['to_confirm']]) }}@endif</span>
                    @if ($card['user']->vehicle?->model)
                        <span class="inventory__muted">🚐 {{ collect([$card['user']->vehicle->model, $card['user']->vehicle->plate])->filter()->implode(' - ') }}</span>
                    @endif
                </a>
            @empty
                <div class="empty-state">{{ __('inventory.empty') }}</div>
            @endforelse
        </div>
    @elseif ($tab === 'attestations')
        <div class="inventory__history">
            @forelse ($attestations as $attestation)
                <article class="inventory__move" wire:key="att-{{ $attestation->id }}">
                    <strong>📄 {{ $attestation->reference }} — {{ $attestation->delivered_on->translatedFormat('d/m/Y') }}</strong>
                    <span>{{ trans_choice('inventory.lines_count', $attestation->items_count, ['count' => $attestation->items_count]) }} · {{ __('inventory.created_by', ['name' => $attestation->created_by_name]) }}</span>
                    <span>
                        <span class="inventory__chip inventory__chip--attestation-{{ $attestation->status->value }}">{{ $attestation->status->label() }}</span>
                        <a class="btn btn--ghost" href="{{ route('equipment.attestations.show', $attestation) }}" target="_blank" rel="noopener">{{ __('inventory.attestation.open_pdf') }}</a>
                    </span>
                </article>
            @empty
                <div class="empty-state">{{ __('inventory.attestation.none') }}</div>
            @endforelse
        </div>
    @else
        <div class="inventory__history">
            @forelse ($moves as $move)
                <article class="inventory__move" wire:key="move-{{ $move->id }}">
                    <strong>{{ $move->type->label() }}</strong>
                    {{ $move->label }}@if ($move->quantity > 1) ×{{ $move->quantity }}@endif
                    @if ($move->from_name && $move->to_name) {{ __('inventory.move_from_to', ['from' => $move->from_name, 'to' => $move->to_name]) }}
                    @elseif ($move->to_name) {{ __('inventory.move_to', ['to' => $move->to_name]) }}
                    @elseif ($move->from_name) {{ __('inventory.move_from', ['from' => $move->from_name]) }}
                    @elseif ($move->holder_name) · {{ $move->holder_name }}@endif
                    @if ($move->kind) ({{ __('enums.inventory_'.($move->type->value === 'request' ? 'request' : 'anomaly').'_kinds.'.$move->kind) }})@endif
                    @if ($move->condition && in_array($move->type->value, ['create', 'noted', 'assign', 'return', 'transfer'], true)) · {{ __('enums.inventory_conditions.'.$move->condition) }}@endif
                    @if ($move->outcome) → {{ __('enums.inventory_statuses.'.$move->outcome) }}@endif
                    @if ($move->comment)<em>« {{ $move->comment }} »</em>@endif
                    <small class="inventory__muted">{{ $move->created_at->setTimezone(config('platform.timezone'))->translatedFormat('d/m/Y H:i') }} · {{ __('inventory.by', ['name' => $move->user_name]) }}</small>
                </article>
            @empty
                <div class="empty-state">{{ __('inventory.no_movement') }}</div>
            @endforelse
        </div>
    @endif
</div>
