@php
    $rowsCollection = collect($rows);
    $countOf = fn (string $key, string $value) => $rowsCollection->where($key, $value)->count();
    $filteredIds = array_column($filtered, 'id');
    $selectedIds = array_map('intval', $selected);
    $allSelected = $filteredIds !== [] && array_diff($filteredIds, $selectedIds) === [];
@endphp

<div class="portal-toolbar">
    <input type="search" class="portal-toolbar__search" wire:model.live.debounce.250ms="search" placeholder="{{ __('client_portal.boxes.search') }}" aria-label="{{ __('client_portal.boxes.search') }}">
    <div class="portal-chips">
        @include('livewire.client-portal.partials.chip', ['property' => 'kind', 'value' => 'all', 'current' => $kind, 'label' => __('client_portal.boxes.all_kinds'), 'count' => count($rows)])
        @foreach (\App\Enums\AuditBoxKind::cases() as $boxKind)
            @include('livewire.client-portal.partials.chip', ['property' => 'kind', 'value' => $boxKind->value, 'current' => $kind, 'label' => __('client_portal.kind.'.$boxKind->value), 'count' => $countOf('kind', $boxKind->value)])
        @endforeach
    </div>
    <div class="portal-chips">
        @include('livewire.client-portal.partials.chip', ['property' => 'support', 'value' => 'all', 'current' => $support, 'label' => __('client_portal.boxes.all_supports')])
        @foreach (['aerial', 'underground'] as $supportKey)
            @include('livewire.client-portal.partials.chip', ['property' => 'support', 'value' => $supportKey, 'current' => $support, 'label' => __('client_portal.support.'.$supportKey), 'count' => $countOf('support', $supportKey)])
        @endforeach
    </div>
</div>

<div class="portal-toolbar">
    <div class="portal-chips">
        @include('livewire.client-portal.partials.chip', ['property' => 'status', 'value' => 'all', 'current' => $status, 'label' => __('client_portal.boxes.all_statuses')])
        @foreach (['ok', 'anomaly', 'partial', 'not_installed', 'todo'] as $statusKey)
            @include('livewire.client-portal.partials.chip', ['property' => 'status', 'value' => $statusKey, 'current' => $status, 'label' => __('client_portal.boxes.status_filter.'.$statusKey), 'count' => $countOf('status', $statusKey)])
        @endforeach
    </div>
    <span class="portal-toolbar__count">{{ __('client_portal.boxes.count', ['count' => count($filtered)]) }}</span>
</div>

@if ($points !== [])
    <div class="portal-toolbar">
        <b class="portal-toolbar__label">{{ __('client_portal.boxes.points') }}</b>
        @foreach ($points as $point)
            <button type="button" class="portal-chip" wire:click="openPoint({{ $point['id'] }})">{{ $point['name'] }}<span class="portal-chip__count">{{ $point['photos'] }} 📷</span>@if ($openByPoint[$point['id']] ?? 0) <span class="portal-badge">{{ $openByPoint[$point['id']] }}</span>@endif</button>
        @endforeach
    </div>
@endif

<div class="portal-table-wrap">
    <table class="portal-table">
        <thead>
            <tr>
                <th class="portal-table__check"><input type="checkbox" @checked($allSelected) wire:click="selectAll({{ json_encode($filteredIds) }}, {{ $allSelected ? 'false' : 'true' }})" aria-label="{{ __('client_portal.boxes.select_all') }}"></th>
                @foreach (\App\Livewire\ClientPortal\PortalPage::SORTS as $column)
                    <th class="portal-table__sortable" wire:click="sortBy('{{ $column }}')">{{ __('client_portal.boxes.columns.'.$column) }}@if ($sort === $column) {{ $direction > 0 ? '▲' : '▼' }}@endif</th>
                @endforeach
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($filtered as $row)
                <tr class="portal-table__row" wire:key="box-{{ $row['id'] }}">
                    <td><input type="checkbox" value="{{ $row['id'] }}" wire:model.live="selected" aria-label="{{ $row['name'] }}"></td>
                    <td class="portal-table__name" wire:click="openBox({{ $row['id'] }})">{{ $row['name'] }}@if ($openByBox[$row['id']] ?? 0) <span class="portal-badge" title="{{ __('client_portal.notes.open_badge') }}">{{ $openByBox[$row['id']] }}</span>@endif</td>
                    <td wire:click="openBox({{ $row['id'] }})">{{ __('client_portal.kind.'.$row['kind']) }}</td>
                    <td wire:click="openBox({{ $row['id'] }})">{{ __('client_portal.support.'.$row['support']) }}@if ($row['support_number'])<div class="portal-meta">{{ $row['support_number'] }}</div>@endif</td>
                    <td wire:click="openBox({{ $row['id'] }})"><span class="portal-pill portal-pill--{{ $row['status'] }}">{{ __('client_portal.status.'.$row['status']) }}</span></td>
                    <td class="portal-table__progress" wire:click="openBox({{ $row['id'] }})">
                        @include('livewire.client-portal.partials.progress', ['percent' => $row['percent']])
                        <small class="portal-meta">{{ $row['percent'] }}%</small>
                    </td>
                    <td wire:click="openBox({{ $row['id'] }})">{{ $row['photos'] }}</td>
                    <td class="portal-table__nowrap" wire:click="openBox({{ $row['id'] }})">{{ $charts->time($row['last'], $now) }}</td>
                    <td class="portal-table__nowrap">
                        <button type="button" class="portal-btn portal-btn--small" wire:click="openNoteDialog({{ $row['id'] }})" title="{{ __('client_portal.notes.add_title') }}">📝 {{ __('client_portal.notes.note_button') }}</button>
                        @if ($row['pdf'])
                            <a class="portal-btn portal-btn--ghost portal-btn--small" href="{{ route('portal.pdf', [$client->portalSlug(), 'box', $row['id'], 'download' => 1]) }}">PDF</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="portal-empty">{{ __('client_portal.boxes.none') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@if ($selected !== [])
    <form class="portal-selbar" method="POST" action="{{ route('portal.zip', $client->portalSlug()) }}">
        @csrf
        @foreach ($selectedIds as $selectedId)
            <input type="hidden" name="items[]" value="box:{{ $selectedId }}">
        @endforeach
        <span>{{ __('client_portal.boxes.selected', ['count' => count($selectedIds)]) }}</span>
        <button type="button" class="portal-btn portal-btn--ghost portal-btn--small" wire:click="clearSelection">{{ __('client_portal.boxes.clear') }}</button>
        <button type="submit" class="portal-btn">⬇ {{ __('client_portal.boxes.download') }}</button>
    </form>
@endif
