<div class="portal-app" wire:poll.15s.visible>
    @if ($account)
        <header class="portal-top">
            <div class="portal-top__inner">
                @include('portal.partials.brand')
                <div class="portal-top__title">{{ __('client_portal.title') }}</div>
                <label class="portal-pick" title="{{ __('client_portal.pick_title') }}">
                    <span class="portal-pick__label">{{ __('client_portal.pick_label') }}</span>
                    <select class="portal-pick__select" wire:model.live="cabinet" aria-label="{{ __('client_portal.pick_title') }}" @disabled($overviews->isEmpty())>
                        @foreach ($overviews as $overview)
                            <option value="{{ $overview['cabinet'] }}">{{ $overview['cabinet'] }}@if ($overview['sro']) — {{ $overview['sro'] }}@endif · {{ $overview['percent'] }}% ({{ $overview['done'] }}/{{ $overview['total'] }})</option>
                        @endforeach
                    </select>
                </label>
                <div class="portal-top__spacer"></div>
                <div class="portal-live" title="{{ __('client_portal.live_title') }}" data-portal-live data-lost="{{ __('client_portal.live_lost') }}">
                    <span class="portal-live__dot"></span>
                    <span class="portal-live__text">{{ __('client_portal.live', ['time' => $now->setTimezone(config('platform.timezone'))->format('H:i:s')]) }}</span>
                </div>
                @include('livewire.client-portal.partials.bell')
                <x-language-switcher />
                <div class="portal-top__who">
                    <span class="portal-top__who-name">{{ $account->name }}</span>
                    <span>{{ $client->name }}</span>
                </div>
                <form class="portal-top__logout" method="POST" action="{{ route('portal.logout', $client->portalSlug()) }}">
                    @csrf
                    <button type="submit" class="portal-btn portal-btn--ghost portal-btn--small">{{ __('client_portal.logout') }}</button>
                </form>
            </div>
            <nav class="portal-tabs">
                @foreach (\App\Livewire\ClientPortal\PortalPage::TABS as $name)
                    @continue(in_array($name, \App\Livewire\ClientPortal\PortalPage::DESIGN_OFFICE_TABS, true) && ! $designOfficeAudit)
                    <button type="button" @class(['portal-tabs__tab', 'is-on' => $tab === $name]) wire:click="showTab('{{ $name }}')">{{ __('client_portal.tabs.'.$name) }}@if ($name === 'notes' && $openRemarks > 0)<span class="portal-tabs__badge">{{ $openRemarks }}</span>@endif</button>
                @endforeach
            </nav>
        </header>

        <main class="portal-main">
            @if ($current === null)
                <div class="portal-card portal-empty">{{ __('client_portal.no_audits') }}</div>
            @elseif ($tab === 'boxes')
                @include('livewire.client-portal.partials.boxes')
            @elseif ($tab === 'notes')
                @include('livewire.client-portal.partials.notes')
            @elseif ($tab === 'dossier')
                @include('livewire.client-portal.partials.dossier')
            @else
                @include('livewire.client-portal.partials.dashboard', ['overview' => $current])
            @endif
        </main>

        @if ($detail)
            @include('livewire.client-portal.partials.drawer')
        @endif

        @if ($noteBoxName !== null)
            @include('livewire.client-portal.partials.note-dialog')
        @endif

        <footer class="portal-footer">{{ __('client_portal.footer', ['company' => $company, 'client' => $client->name]) }}</footer>
    @endif
</div>
