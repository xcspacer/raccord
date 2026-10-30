<div class="users-page">
    <div class="absences-toolbar">
        <input class="search-input" type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('logs.search') }}" aria-label="{{ __('logs.search') }}">
        <select class="absences-month-select" wire:model.live="type" aria-label="{{ __('logs.type') }}">
            <option value="">{{ __('logs.all_types') }}</option>
            @foreach ($types as $option)
                <option value="{{ $option }}">{{ __('logs.types.'.$option) }}</option>
            @endforeach
        </select>
        <button type="button" class="btn btn--ghost" wire:click="$refresh">↻ {{ __('logs.refresh') }}</button>
    </div>

    @if ($workflow !== null)
        <div class="logs-chain">
            <p class="page-hint">{{ trans_choice('logs.workflow.count', $workflow->count(), ['count' => $workflow->count()]) }}</p>
            <div class="logs-chain__status">
                @if ($chain === null)
                    <span>{{ __('logs.workflow.not_checked') }}</span>
                @elseif ($chain['intact'])
                    <span class="status-badge status-badge--leave">✓ {{ trans_choice('logs.workflow.intact', $chain['checked'], ['count' => $chain['checked']]) }}</span>
                @else
                    <span class="status-badge status-badge--absent">⚠ {{ __('logs.workflow.broken', ['id' => $chain['broken_at']]) }}</span>
                @endif
                <button type="button" class="btn btn--ghost btn--small" wire:click="verifyChain" wire:loading.attr="disabled">{{ __('logs.workflow.verify') }}</button>
            </div>
        </div>

        @if ($workflow->isEmpty())
            <div class="empty-state">{{ $filtered ? __('logs.empty_filtered') : __('logs.empty') }}</div>
        @else
            <div class="users-grid">
                @foreach ($workflow as $entry)
                    <article class="user-card" wire:key="journal-{{ $entry['id'] }}">
                        <header class="user-card__head">
                            <div>
                                <div class="user-card__name">{{ $entry['event'] }}@if ($entry['subject'] !== '') <small>· {{ $entry['subject'] }}</small>@endif</div>
                                <div class="user-card__username">{{ $entry['at']?->timezone(config('platform.timezone'))->translatedFormat('d M Y H:i:s') }} — {{ $entry['author'] ?? __('logs.workflow.system') }}</div>
                            </div>
                            <code class="logs-chain__hash" title="{{ __('logs.workflow.hash') }}">#{{ $entry['id'] }} · {{ $entry['hash'] }}</code>
                        </header>
                        @if ($entry['details'] !== '')
                            <div class="absence-block"><div class="absence-block__item"><span>{{ $entry['details'] }}</span></div></div>
                        @endif
                    </article>
                @endforeach
            </div>
            @if ($canLoadMore)
                <p class="page-hint"><button type="button" class="btn btn--ghost" wire:click="loadMore">{{ __('logs.load_more') }}</button></p>
            @endif
        @endif
    @else
    <p class="page-hint">{{ trans_choice('logs.count', $events->count(), ['count' => $events->count()]) }}</p>

    @if ($events->isEmpty())
        <div class="empty-state">{{ $filtered ? __('logs.empty_filtered') : __('logs.empty') }}</div>
    @else
        @if ($restorableIds !== [])
            @php($selectedCount = count(array_intersect($selectedIds, $restorableIds)))
            <div class="logs-bulk">
                <label class="logs-bulk__all">
                    <input type="checkbox" class="logs-checkbox" @checked($selectedCount === count($restorableIds)) wire:click="toggleMany({{ json_encode($restorableIds) }}, $event.target.checked)">
                    {{ __('logs.select_all', ['count' => count($restorableIds)]) }}
                </label>
                <span class="logs-bulk__count">@if ($selectedCount > 0){{ trans_choice('logs.selected', $selectedCount, ['count' => $selectedCount]) }}@endif</span>
                <button type="button" class="btn btn--primary" wire:click="restore" wire:confirm="{{ trans_choice('logs.confirm_restore', $selectedCount, ['count' => $selectedCount]) }}" @disabled($selectedCount === 0)>↺ {{ trans_choice('logs.restore_selection', $selectedCount, ['count' => $selectedCount]) }}</button>
                <button type="button" class="btn btn--ghost" wire:click="clearSelection">{{ __('logs.clear_selection') }}</button>
            </div>
        @endif

        <div class="users-grid">
            @foreach ($events as $index => $event)
                @php($eventIds = $event['changes']->where('restorable', true)->pluck('id')->all())
                @php($eventSelected = array_values(array_intersect($eventIds, $selectedIds)))
                <article class="user-card" wire:key="event-{{ $index }}-{{ $event['changes']->first()['id'] }}">
                    <header class="user-card__head">
                        <div>
                            <div class="user-card__name">{{ __('logs.event_title.'.$event['type']) }} — {{ trans_choice('logs.changes', $event['changes']->count(), ['count' => $event['changes']->count()]) }}</div>
                            <div class="user-card__username">{{ $event['at']?->timezone(config('platform.timezone'))->translatedFormat('d M Y, H:i') }} — {{ $event['author'] ?? __('logs.unknown_user') }}</div>
                        </div>
                        @if ($eventIds !== [])
                            <div class="logs-event__actions">
                                @if ($eventSelected !== [])
                                    <button type="button" class="btn btn--primary btn--small" wire:click="restore({{ json_encode($eventSelected) }})" wire:confirm="{{ trans_choice('logs.confirm_restore', count($eventSelected), ['count' => count($eventSelected)]) }}">↺ {{ __('logs.restore_count', ['count' => count($eventSelected)]) }}</button>
                                @endif
                                <label class="logs-bulk__all">
                                    <input type="checkbox" class="logs-checkbox" @checked(count($eventSelected) === count($eventIds)) wire:click="toggleMany({{ json_encode($eventIds) }}, $event.target.checked)">
                                    {{ __('logs.whole_event') }}
                                </label>
                            </div>
                        @endif
                    </header>

                    <div class="absence-block">
                        @foreach ($event['changes'] as $change)
                            <div class="absence-block__item" wire:key="change-{{ $change['id'] }}">
                                <span>
                                    @if ($change['restorable'])
                                        <input type="checkbox" class="logs-checkbox" @checked(in_array($change['id'], $selectedIds, true)) wire:click="toggleMany([{{ $change['id'] }}], $event.target.checked)" aria-label="{{ __('logs.select') }}">
                                    @endif
                                    <span class="status-badge status-badge--{{ ['deleted' => 'absent', 'created' => 'leave'][$change['op']] ?? 'sick' }}">{{ __('logs.ops.'.$change['op']) }}</span>
                                    {{ $change['summary'] }}
                                </span>
                                @if ($change['restorable'])
                                    <button type="button" class="btn btn--ghost" wire:click="restore([{{ $change['id'] }}])" wire:confirm="{{ trans_choice('logs.confirm_restore', 1, ['count' => 1]) }}">↺ {{ __('logs.restore') }}</button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </article>
            @endforeach
        </div>

        @if ($canLoadMore)
            <p class="page-hint"><button type="button" class="btn btn--ghost" wire:click="loadMore">{{ __('logs.load_more') }}</button></p>
        @endif
    @endif
    @endif
</div>
