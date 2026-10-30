<div class="tasks-panel" @unless ($selecting) wire:poll.60s.visible @endunless>
    <div class="week-nav">
        <button type="button" class="btn btn--ghost" wire:click="previousWeek">◀ <span class="week-nav__full">{{ __('tasks.previous_week') }}</span><span class="week-nav__short">S{{ $previousWeekNumber }}</span></button>
        <div class="week-nav__label">
            <span class="week-nav__full">{{ $monday->translatedFormat('d F') }} → {{ $monday->addDays(5)->translatedFormat('d F Y') }}</span>
            <span class="week-nav__short">S{{ $monday->isoWeek }}</span>
        </div>
        <div class="week-nav__buttons">
            @unless ($technicianView)
                <button type="button" @class(['btn', 'btn--ghost', 'is-active' => $selecting]) wire:click="toggleSelecting" title="{{ __('tasks.select_title') }}">☑ {{ __('tasks.select') }}</button>
            @endunless
            @if ($canExportTimesheet)
                <a class="btn btn--ghost" href="{{ route('tasks.timesheet', ['week' => $monday->toDateString()]) }}">📊 {{ __('weekly_timesheet.button') }}</a>
            @endif
            <button type="button" class="btn btn--ghost" wire:click="currentWeek">{{ __('tasks.today') }}</button>
            <button type="button" class="btn btn--ghost" wire:click="nextWeek"><span class="week-nav__full">{{ __('tasks.next_week') }}</span><span class="week-nav__short">S{{ $nextWeekNumber }}</span> ▶</button>
        </div>
    </div>

    @if ($people->isEmpty())
        <div class="empty-state">{{ __('tasks.no_technicians') }}</div>
    @elseif ($technicianView)
        @php $me = $people->first(); @endphp
        <div class="tasks-vehicle">{{ $me->vehicle?->model ? '🚐 '.$me->vehicle->model.($me->vehicle->plate ? ' - '.$me->vehicle->plate : '') : __('tasks.no_vehicle') }}</div>
        <div class="task-cards">
            @foreach ($days as $day)
                @php $cell = $cells[$me->id][$day->toDateString()]; @endphp
                <div @class(['task-card', 'is-today' => $day->isSameDay($today), 'is-clickable' => $cell['task']]) wire:key="card-{{ $day->toDateString() }}" @if ($cell['task']) wire:click="openDetail('{{ $day->toDateString() }}')" @endif>
                    <div class="task-card__head"><span>{{ $day->translatedFormat('l') }}</span><span class="task-card__number">{{ $day->day }}</span></div>
                    @include('livewire.planning.partials.task-body', ['cell' => $cell, 'withSiteLink' => false])
                    @if (! $cell['task'] && ! $cell['absence'] && ! $cell['punch'])
                        <div class="task-empty">{{ __('tasks.nothing_planned') }}</div>
                    @endif
                </div>
            @endforeach
        </div>
    @else
        @php
            $allIds = collect($cells)->flatten(1)->map(fn ($cell) => $cell['task']?->id)->filter()->values()->all();
            $selectedCount = count($selected);
        @endphp

        @if ($selecting)
            <div class="selection-bar">
                <span class="selection-bar__count">{{ $selectedCount > 0 ? trans_choice('tasks.selected', $selectedCount, ['count' => $selectedCount]) : __('tasks.no_selection') }}</span>
                <button type="button" class="btn btn--ghost" wire:click="toggleMany({{ json_encode($allIds) }})">{{ __('tasks.whole_week') }}</button>
                @if ($groups !== [])
                    <select class="select-filter" wire:model.live="group" aria-label="{{ __('tasks.add_group') }}">
                        <option value="">{{ __('tasks.add_group') }}</option>
                        @foreach ($groups as $option)
                            <option value="{{ $option['key'] }}">{{ $option['label'] }} ({{ $option['count'] }})</option>
                        @endforeach
                    </select>
                @endif
                <button type="button" class="btn btn--ghost" wire:click="clearSelection" @disabled($selectedCount === 0)>{{ __('tasks.none') }}</button>
                <button type="button" class="btn btn--ghost btn--danger" wire:click="askDeleteSelected" @disabled($selectedCount === 0)>🗑 {{ __('tasks.delete') }}</button>
                <button type="button" class="btn btn--ghost" wire:click="toggleSelecting">✕ {{ __('tasks.quit') }}</button>
            </div>
        @endif

        <div class="tasks-scroll">
            <table class="tasks-table">
                <thead>
                    <tr>
                        <th class="tasks-table__person">{{ __('tasks.technician') }}</th>
                        @foreach ($days as $day)
                            @php $dayIds = collect($cells)->map(fn ($row) => $row[$day->toDateString()]['task']?->id)->filter()->values()->all(); @endphp
                            <th @class(['is-today' => $day->isSameDay($today)])>{{ $day->translatedFormat('D') }}<br><span class="tasks-table__date">{{ $day->day }}</span>@if ($selecting)<br><input type="checkbox" class="logs-checkbox" @checked($dayIds !== [] && array_diff($dayIds, $selected) === []) wire:click="toggleMany({{ json_encode($dayIds) }})" title="{{ __('tasks.select_column') }}">@endif</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody data-task-rows>
                    @foreach ($people as $person)
                        @php $rowIds = collect($cells[$person->id])->map(fn ($cell) => $cell['task']?->id)->filter()->values()->all(); @endphp
                        <tr wire:key="person-{{ $person->id }}" data-person-id="{{ $person->id }}">
                            <td class="tasks-table__person">
                                <div class="tasks-person">
                                    <span class="tasks-person__name">
                                        @if ($selecting)
                                            <input type="checkbox" class="logs-checkbox" @checked($rowIds !== [] && array_diff($rowIds, $selected) === []) wire:click="toggleMany({{ json_encode($rowIds) }})" title="{{ __('tasks.select_row') }}">
                                        @else
                                            <span class="tasks-person__handle" data-row-handle title="{{ __('tasks.drag_row') }}" aria-label="{{ __('tasks.drag_row') }}">⠿</span>
                                        @endif
                                        {{ $person->name }}
                                    </span>
                                    <span class="tasks-person__actions">
                                        <button type="button" class="icon-mini" wire:click="openTechnician({{ $person->id }})" title="{{ __('tasks.technician_modal.edit') }}" aria-label="{{ __('tasks.technician_modal.edit') }}">✎</button>
                                        <button type="button" class="icon-mini" wire:click="move({{ $person->id }}, -1)" title="{{ __('tasks.move_up') }}" aria-label="{{ __('tasks.move_up') }}" @disabled($loop->first)>↑</button>
                                        <button type="button" class="icon-mini" wire:click="move({{ $person->id }}, 1)" title="{{ __('tasks.move_down') }}" aria-label="{{ __('tasks.move_down') }}" @disabled($loop->last)>↓</button>
                                        <button type="button" class="icon-mini icon-mini--danger" wire:click="askRemove({{ $person->id }})" title="{{ __('tasks.remove_week') }}" aria-label="{{ __('tasks.remove_week') }}">✕</button>
                                    </span>
                                </div>
                                <div class="tasks-person__vehicle">{{ $person->vehicle?->model ? $person->vehicle->model.($person->vehicle->plate ? ' - '.$person->vehicle->plate : '') : __('tasks.no_vehicle_short') }}</div>
                            </td>
                            @foreach ($days as $day)
                                @php $cell = $cells[$person->id][$day->toDateString()]; @endphp
                                @if ($cell['departed'])
                                    <td class="tasks-cell" data-label="{{ $day->translatedFormat('l d M') }}"><span class="task-badge task-badge--absent">🚪 {{ __('tasks.departed') }}</span></td>
                                @elseif ($selecting)
                                    <td @class(['tasks-cell', 'is-editable' => $cell['task'], 'is-empty' => ! $cell['task'] && ! $cell['absence'] && ! $cell['punch'], 'is-today' => $day->isSameDay($today), 'is-selected' => $cell['task'] && in_array($cell['task']->id, $selected, true)]) data-label="{{ $day->translatedFormat('l d M') }}" @if ($cell['task']) wire:click="toggleTask({{ $cell['task']->id }})" @endif>
                                        @if ($cell['task'])
                                            <input type="checkbox" class="logs-checkbox task-select-box" @checked(in_array($cell['task']->id, $selected, true)) aria-label="{{ __('logs.select') }}">
                                        @endif
                                        @include('livewire.planning.partials.task-body', ['cell' => $cell, 'withSiteLink' => false])
                                    </td>
                                @else
                                    <td @class(['tasks-cell', 'is-editable', 'is-empty' => ! $cell['task'] && ! $cell['absence'] && ! $cell['punch'], 'is-today' => $day->isSameDay($today)]) data-label="{{ $day->translatedFormat('l d M') }}" data-user-id="{{ $person->id }}" data-date="{{ $day->toDateString() }}" @if ($cell['task']) data-task-id="{{ $cell['task']->id }}" data-task-version="{{ $cell['task']->version }}" @endif wire:click="openTask({{ $person->id }}, '{{ $day->toDateString() }}')">
                                        @include('livewire.planning.partials.task-body', ['cell' => $cell, 'withSiteLink' => true])
                                    </td>
                                @endif
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($removed->isNotEmpty())
            <div class="tasks-removed">
                {{ __('tasks.removed_this_week') }}
                @foreach ($removed as $person)
                    <button type="button" class="btn btn--ghost" wire:click="restoreToWeek({{ $person->id }})" wire:key="removed-{{ $person->id }}">↩ {{ $person->name }}</button>
                @endforeach
            </div>
        @endif
    @endif

    @if ($undoVisible && $undo)
        <div class="undo-bar" data-expire-at="{{ $undo['expiresAt'] }}" role="status">
            <span>{{ trans_choice('tasks.deleted', $undo['tasks'], ['count' => $undo['tasks']]) }}@if ($undo['sites'] > 0) · {{ trans_choice('tasks.audit_sites_deleted', $undo['sites'], ['count' => $undo['sites']]) }}@endif</span>
            <button type="button" class="btn btn--ghost" wire:click="undoDelete">↩ {{ __('tasks.undo') }}</button>
            <button type="button" class="icon-mini" wire:click="dismissUndo" aria-label="{{ __('profile.close') }}">✕</button>
        </div>
    @endif

    @if ($technicianView)
        <livewire:planning.task-detail-modal />
    @endif

    @unless ($technicianView)
        <livewire:planning.task-editor />
        <livewire:planning.technician-editor />
        <livewire:work-sites.work-site-editor />
    @endunless
</div>
