<section class="active-sessions">
    <button type="button" class="btn btn--ghost" wire:click="toggle" aria-expanded="{{ $expanded ? 'true' : 'false' }}">🔐 {{ __('sessions.title') }}</button>

    @if ($expanded)
        <ul class="active-sessions__list">
            @forelse ($sessions as $session)
                <li class="active-sessions__item" wire:key="session-{{ $session['key'] }}">
                    <div class="active-sessions__device">
                        <strong>{{ $session['device'] }}</strong>
                        @if ($session['current'])
                            <span class="status-badge active-sessions__current">{{ __('sessions.this_device') }}</span>
                        @endif
                        <small>
                            {{ __('sessions.last_active', ['date' => $session['last_active_at']->timezone(config('platform.timezone'))->translatedFormat('d M Y H:i')]) }}
                            @if ($session['ip']) · {{ $session['ip'] }} @endif
                        </small>
                    </div>

                    @unless ($session['current'])
                        <button type="button" class="btn btn--ghost btn--small btn--danger" wire:click="revoke('{{ $session['key'] }}')">{{ __('sessions.revoke') }}</button>
                    @endunless
                </li>
            @empty
                <li class="active-sessions__empty">{{ __('sessions.none') }}</li>
            @endforelse
        </ul>

        @if (collect($sessions)->contains('current', false))
            <button type="button" class="btn btn--ghost btn--small btn--danger" wire:click="revokeAll" wire:confirm="{{ $isSelf ? __('sessions.confirm_revoke_others') : __('sessions.confirm_revoke_all', ['name' => $user->name]) }}">
                {{ $isSelf ? __('sessions.revoke_others') : __('sessions.revoke_all') }}
            </button>
        @endif
    @endif
</section>
