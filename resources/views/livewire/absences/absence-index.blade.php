<div class="users-page" wire:poll.60s.visible>
    <div class="absences-toolbar">
        <input class="search-input" type="search" wire:model.live.debounce.250ms="search" placeholder="{{ __('absences.search') }}" aria-label="{{ __('absences.search') }}">
        <select class="absences-month-select" wire:model.live="month" aria-label="{{ __('absences.month') }}">
            @foreach ($months as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
        <button type="button" class="btn btn--ghost" wire:click="export" wire:loading.attr="disabled" wire:target="export">
            <span wire:loading.remove wire:target="export">📊 {{ __('absences.export.button') }}</span>
            <span wire:loading wire:target="export">{{ __('absences.export.preparing') }}</span>
        </button>
    </div>

    <p class="page-hint">{{ $countText }}</p>

    <div class="users-grid">
        @forelse ($people as $person)
            <article class="user-card" wire:key="absences-{{ $person->id }}">
                <header class="user-card__head">
                    <div>
                        <div class="user-card__name">{{ $person->name }}</div>
                        <div class="user-card__username">{{ '@'.$person->username }}</div>
                    </div>
                    <div class="user-card__badges">
                        @if ($isCurrentMonth && ($todayType = $calendars[$person->id]['today']))
                            <span class="status-badge status-badge--{{ $todayType->value }}">{{ __('users.badges.absence_today.'.$todayType->value) }}</span>
                        @endif
                    </div>
                </header>

                <livewire:absences.absence-manager :user="$person" :month="$monthValue" :wire:key="'absence-manager-'.$person->id" />
            </article>
        @empty
            <div class="empty-state">{{ __('absences.no_match', ['query' => $search]) }}</div>
        @endforelse
    </div>
</div>
