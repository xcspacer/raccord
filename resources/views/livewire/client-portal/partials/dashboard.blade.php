@php
    $ring = $charts->ring($overview['percent']);
    $days = $charts->days($overview['per_day'], $now);
    $left = $overview['total'] - $overview['done'];
    $live = collect($overview['live'])->sortBy(fn ($entry) => $entry['state'] === 'on_box' ? 0 : 1)->values();
    $anomalies = collect($overview['anomalies'])->sortByDesc(fn ($entry) => $entry['at']?->getTimestamp() ?? 0)->values();
@endphp

<div class="portal-grid portal-grid--kpis">
    <div class="portal-card portal-kpi portal-kpi--ring">
        <svg width="{{ $ring['size'] }}" height="{{ $ring['size'] }}" viewBox="0 0 {{ $ring['size'] }} {{ $ring['size'] }}" aria-hidden="true">
            <circle class="portal-ring__track" cx="{{ $ring['centre'] }}" cy="{{ $ring['centre'] }}" r="{{ $ring['radius'] }}"/>
            <circle class="portal-ring__value" cx="{{ $ring['centre'] }}" cy="{{ $ring['centre'] }}" r="{{ $ring['radius'] }}" stroke-dasharray="{{ $ring['circumference'] }}" stroke-dashoffset="{{ $ring['offset'] }}" transform="rotate(-90 {{ $ring['centre'] }} {{ $ring['centre'] }})"/>
            <text class="portal-ring__text" x="50%" y="52%" text-anchor="middle" dominant-baseline="middle" font-size="{{ $ring['font'] }}">{{ $overview['percent'] }}%</text>
        </svg>
        <div>
            <h3 class="portal-kpi__ring-title">{{ __('client_portal.dashboard.overall') }}</h3>
            <div class="portal-kpi__sub">{{ $overview['cabinet'] }}@if ($overview['sro'])<br>{{ $overview['sro'] }}@endif</div>
            @if ($overview['validated'])
                <div class="portal-pill portal-pill--ok portal-kpi__pill">{{ __('client_portal.dashboard.validated') }}</div>
            @elseif ($overview['finished'])
                <div class="portal-pill portal-pill--answered portal-kpi__pill">{{ __('client_portal.dashboard.in_validation') }}</div>
            @endif
        </div>
    </div>
    <div class="portal-card portal-kpi portal-kpi--green">
        <h3>{{ __('client_portal.dashboard.finished') }}</h3>
        <div class="portal-kpi__value">{{ $overview['done'] }}<small> / {{ $overview['total'] }}</small></div>
        <div class="portal-kpi__sub">{{ __('client_portal.dashboard.compliant', ['count' => $overview['by_status']['ok']]) }}</div>
    </div>
    <div class="portal-card portal-kpi portal-kpi--amber">
        <h3>{{ __('client_portal.dashboard.remaining') }}</h3>
        <div class="portal-kpi__value">{{ $left }}</div>
        <div class="portal-kpi__sub">{{ __('client_portal.dashboard.remaining_detail', ['partial' => $overview['by_status']['partial'], 'todo' => $overview['by_status']['todo']]) }}</div>
    </div>
    <div class="portal-card portal-kpi portal-kpi--red">
        <h3>{{ __('client_portal.dashboard.anomalies') }}</h3>
        <div class="portal-kpi__value">{{ $overview['by_status']['anomaly'] }}</div>
        <div class="portal-kpi__sub">{{ $overview['by_status']['not_installed'] ? __('client_portal.dashboard.boxes_not_installed', ['count' => $overview['by_status']['not_installed']]) : __('client_portal.dashboard.to_handle') }}</div>
    </div>
    <div class="portal-card portal-kpi">
        <h3>{{ __('client_portal.dashboard.notes') }}</h3>
        <div class="portal-kpi__value">{{ $openRemarks }}</div>
        <div class="portal-kpi__sub">{{ __('client_portal.dashboard.notes_open') }}<br><b>{{ $overview['last_activity'] ? $charts->ago($overview['last_activity'], $now) : '—' }}</b></div>
    </div>
</div>

<div class="portal-grid portal-grid--two portal-row">
    <div class="portal-card">
        <h3>{{ __('client_portal.dashboard.by_support') }}</h3>
        @include('livewire.client-portal.partials.segment', ['label' => __('client_portal.support_long.aerial'), 'bucket' => $overview['by_support']['aerial']])
        @include('livewire.client-portal.partials.segment', ['label' => __('client_portal.support_long.underground'), 'bucket' => $overview['by_support']['underground']])
        @include('livewire.client-portal.partials.segment', ['label' => __('client_portal.support_long.unknown'), 'bucket' => $overview['by_support']['unknown']])
        <div class="portal-legend">
            @foreach (\App\ClientPortal\PortalOverview::STATUSES as $status)
                <span class="portal-legend__item portal-legend__item--{{ $status }}">{{ __('client_portal.status.'.$status) }}</span>
            @endforeach
        </div>
    </div>
    <div class="portal-card">
        <h3>{{ __('client_portal.dashboard.by_kind') }}</h3>
        @foreach (\App\Enums\AuditBoxKind::cases() as $kind)
            @include('livewire.client-portal.partials.segment', ['label' => __('client_portal.kind.'.$kind->value), 'bucket' => $overview['by_kind'][$kind->value]])
        @endforeach
    </div>
</div>

<div class="portal-grid portal-grid--two portal-row">
    <div class="portal-card">
        <h3>{{ __('client_portal.dashboard.per_day') }} <span class="portal-card__aside">{{ __('client_portal.dashboard.per_day_unit') }}</span></h3>
        @if ($days === null)
            <div class="portal-empty">{{ __('client_portal.dashboard.no_production') }}</div>
        @else
            <svg class="portal-chart" viewBox="0 0 {{ $days['width'] }} {{ $days['height'] }}" preserveAspectRatio="none" aria-hidden="true">
                @foreach ($days['grid'] as $line)
                    <line class="portal-chart__grid" x1="{{ $days['left'] }}" x2="{{ $days['width'] }}" y1="{{ $line['y'] }}" y2="{{ $line['y'] }}"/>
                    <text class="portal-chart__axis" x="{{ $days['left'] - 6 }}" y="{{ $line['y'] + 4 }}" text-anchor="end">{{ $line['value'] }}</text>
                @endforeach
                @foreach ($days['bars'] as $bar)
                    <rect class="portal-chart__bar" x="{{ $bar['x'] }}" y="{{ $bar['y'] }}" width="{{ $bar['width'] }}" height="{{ $bar['height'] }}" rx="4"><title>{{ __('client_portal.dashboard.day_title', ['day' => $bar['day'], 'count' => $bar['count']]) }}</title></rect>
                    @if ($bar['count'])
                        <text class="portal-chart__count" x="{{ $bar['centre'] }}" y="{{ $bar['y'] - 4 }}" text-anchor="middle">{{ $bar['count'] }}</text>
                    @endif
                    <text class="portal-chart__axis" x="{{ $bar['centre'] }}" y="{{ $days['height'] - 8 }}" text-anchor="middle">{{ $bar['label'] }}</text>
                @endforeach
            </svg>
        @endif
    </div>
    <div class="portal-card">
        <h3><span><span class="portal-live__dot portal-live__dot--inline"></span>{{ __('client_portal.dashboard.live') }}</span><span class="portal-card__aside">{{ __('client_portal.dashboard.live_count', ['count' => $live->count()]) }}</span></h3>
        @if ($live->isNotEmpty())
            <div class="portal-live-list">
                @foreach ($live as $entry)
                    <div @class(['portal-live-item', 'portal-live-item--photo' => $entry['state'] === 'photo']) wire:click="openBox({{ $entry['id'] }})">
                        <div>
                            <b>{{ $entry['box'] }}</b>
                            <div class="portal-meta">{{ __('client_portal.dashboard.live_'.$entry['state']) }}@if ($entry['name']) · {{ __('client_portal.dashboard.crew', ['name' => $entry['name']]) }}@endif</div>
                        </div>
                        <div class="portal-live-item__time">{{ __('client_portal.dashboard.since') }}<br><b>{{ $charts->shortTime($entry['since'], $now) }}</b></div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="portal-empty">{{ __('client_portal.dashboard.nobody') }}<br>{{ __('client_portal.dashboard.last_activity') }} <b>{{ $overview['last_activity'] ? $charts->time($overview['last_activity'], $now).' ('.$charts->ago($overview['last_activity'], $now).')' : '—' }}</b></div>
        @endif
    </div>
</div>

<div class="portal-grid portal-grid--two-eq portal-row">
    <div class="portal-card">
        <h3>{{ __('client_portal.dashboard.anomalies_seen') }} <span class="portal-card__aside">{{ $anomalies->count() }}</span></h3>
        @forelse ($anomalies->take(12) as $anomaly)
            <div class="portal-list-row" wire:click="openBox({{ $anomaly['id'] }})">
                <div>
                    <b>{{ $anomaly['box'] }}</b> <span class="portal-meta">· {{ __('client_portal.kind.'.$anomaly['kind']) }} · {{ __('client_portal.support.'.$anomaly['support']) }}</span>
                    <div class="portal-meta">{{ $anomaly['reason'] !== '' ? $anomaly['reason'] : __('client_portal.dashboard.no_reason') }}</div>
                </div>
                <div class="portal-meta portal-list-row__time">{{ $charts->time($anomaly['at'], $now) }}</div>
            </div>
        @empty
            <div class="portal-empty">{{ __('client_portal.dashboard.no_anomalies') }}</div>
        @endforelse
        @if ($anomalies->count() > 12)
            <div class="portal-meta portal-list-row__more">{{ __('client_portal.dashboard.more_anomalies') }}</div>
        @endif
    </div>
    <div class="portal-card">
        <h3>{{ __('client_portal.dashboard.activity') }}</h3>
        @forelse ($overview['activity'] as $event)
            <div class="portal-feed-row">
                <time>{{ $charts->shortTime($event['at'], $now) }}</time>
                <div><b>{{ $event['box'] }}</b> — {{ __('client_portal.dashboard.photo_in', ['folder' => $event['folder']]) }}@if ($event['name']) <span class="portal-meta">· {{ $event['name'] }}</span>@endif</div>
            </div>
        @empty
            <div class="portal-empty">{{ __('client_portal.dashboard.no_activity') }}</div>
        @endforelse
    </div>
</div>
