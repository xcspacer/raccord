<details class="portal-bell" wire:ignore.self>
    <summary class="portal-bell__button" title="{{ __('client_portal.notes.notifications') }}" aria-label="{{ __('client_portal.notes.notifications') }}" wire:click="markNotificationsSeen">
        🔔@if ($unseen > 0)<span class="portal-bell__badge">{{ $unseen }}</span>@endif
    </summary>
    <div class="portal-bell__menu">
        @php($highlightSince = $seenBefore !== null ? \Carbon\CarbonImmutable::parse($seenBefore) : $account->notifications_seen_at)
        @forelse ($events as $event)
            <button type="button" @class(['portal-bell__item', 'is-new' => $highlightSince === null || $event['at']->gt($highlightSince)]) wire:click="{{ $event['target'] ? ($event['type'] === 'point' ? 'openPoint' : 'openBox').'('.$event['target'].')' : ($event['type'] === 'dossier' ? 'showTab(\'dossier\')' : 'showTab(\'notes\')') }}">
                <span>{{ $event['text'] }}</span>
                <small>{{ $charts->time($event['at'], $now) }}</small>
            </button>
        @empty
            <div class="portal-bell__item"><small>{{ __('client_portal.notes.no_notifications') }}</small></div>
        @endforelse
    </div>
</details>
