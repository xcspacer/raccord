<div wire:poll.60s.visible>
    @if ($visible)
        <div class="overlay overlay--gate" data-day-punch data-timezone="{{ config('platform.timezone') }}" data-labels="{{ json_encode(__('day_punch.js'), JSON_UNESCAPED_UNICODE) }}" data-endpoint="{{ url('/day-punch') }}">
            <div class="modal gate" role="dialog" aria-modal="true" aria-labelledby="gate-title">
                <h2 id="gate-title">{{ __('day_punch.gate.title') }}</h2>
                <p class="gate__clock" data-punch-clock>{{ $now->translatedFormat('l d F') }} — {{ now(config('platform.timezone'))->format('H:i') }}</p>
                <p class="gate__hint">{{ __('day_punch.gate.hint') }}</p>
                <button type="button" class="btn btn--primary btn--block gate__start" data-punch-start>▶ {{ __('day_punch.gate.start') }}</button>
                <p class="gate__message" data-punch-message role="status"></p>
                <p class="privacy-notice">{{ __('day_punch.gate.privacy', ['months' => config('platform.location_retention_months')]) }}</p>
                <form method="POST" action="{{ route('logout') }}" class="gate__logout" data-pending-confirm="{{ __('app.logout_pending') }}">
                    @csrf
                    <button type="submit" class="btn btn--ghost">{{ __('app.logout') }}</button>
                </form>
            </div>
        </div>
    @endif
</div>
