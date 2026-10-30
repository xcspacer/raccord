@if ($bucket['total'] > 0)
    <div class="portal-seg">
        <div class="portal-seg__head">
            <b>{{ $label }}</b>
            <span>{{ __('client_portal.dashboard.done_of', ['done' => $bucket['done'], 'total' => $bucket['total']]) }} · <b class="portal-seg__percent">{{ (int) round($bucket['done'] * 100 / $bucket['total']) }}%</b></span>
        </div>
        @include('livewire.client-portal.partials.stack', ['bucket' => $bucket])
        <div class="portal-mini">
            <span>{{ __('client_portal.dashboard.left', ['count' => $bucket['total'] - $bucket['done']]) }}</span>
            @if ($bucket['anomaly']) <span class="portal-mini__alert">{{ __('client_portal.dashboard.anomalies_count', ['count' => $bucket['anomaly']]) }}</span> @endif
            @if ($bucket['partial']) <span>{{ __('client_portal.dashboard.to_redo', ['count' => $bucket['partial']]) }}</span> @endif
            @if ($bucket['not_installed']) <span>{{ __('client_portal.dashboard.not_installed', ['count' => $bucket['not_installed']]) }}</span> @endif
        </div>
    </div>
@endif
