<x-slot:header>
    <livewire:planning.capacity-panel part="box" />
</x-slot:header>

<div wire:poll.60s.visible>
    <livewire:planning.capacity-panel part="alert" />

    <div class="toolbar">
        <div class="toolbar__filters">
            <input class="search-input" type="search" wire:model.live.debounce.250ms="search" placeholder="{{ __('work_sites.search') }}" aria-label="{{ __('work_sites.search') }}">
            <select class="select-filter" wire:model.live="status" aria-label="{{ __('work_sites.fields.status') }}">
                <option value="">{{ __('work_sites.all_statuses') }}</option>
                @foreach ($statuses as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
                @if ($canSeeAbsences)
                    <option value="absences">{{ __('planning.absences_filter') }}</option>
                @endif
            </select>
        </div>

        @if ($canCreate)
            <button type="button" class="btn btn--primary toolbar__in-menu" wire:click="openEditor">+ {{ __('work_sites.new') }}</button>
        @endif
    </div>

    <div class="timeline-panel">
        @if ($timeline === null)
            <div class="timeline-empty">{{ __('planning.empty') }}</div>
        @else
            <div class="timeline-scroll" data-scroll-today="{{ $timeline['todayIndex'] }}">
                <div class="timeline timeline--w{{ $timeline['dayWidth'] }}" data-vars="total:{{ $timeline['total'] + 1 }};lanes:{{ max($timeline['lanes'], 1) }}">
                    <div class="timeline__header">
                        <div class="timeline__row">
                            @foreach ($timeline['months'] as $month)
                                <div class="timeline__month" data-vars="span:{{ $month['span'] }}">{{ $month['label'] }}</div>
                            @endforeach
                        </div>
                        <div class="timeline__row">
                            @foreach ($timeline['weeks'] as $week)
                                <div class="timeline__week" data-vars="span:{{ $week['span'] }}">{{ $week['label'] }}</div>
                            @endforeach
                        </div>
                    </div>
                    <div class="timeline__row timeline__row--days">
                        @foreach ($timeline['days'] as $day)
                            <div @class(['timeline__day', 'is-today' => $day['today']])>{{ $day['number'] }}</div>
                        @endforeach
                    </div>

                    <div class="timeline__body">
                        @foreach ($timeline['stripes'] as $stripe)
                            <div class="timeline__stripe" data-vars="start:{{ $stripe['index'] }}" title="{{ $stripe['title'] }}"></div>
                        @endforeach
                        @if ($timeline['todayIndex'] !== null)
                            <div class="timeline__today" data-vars="start:{{ $timeline['todayIndex'] }}"></div>
                        @endif
                        @foreach ($timeline['bars'] as $bar)
                            <button type="button" wire:key="bar-{{ $bar['id'] }}" @class(['timeline__bar', 'timeline__bar--'.$bar['status']]) data-vars="start:{{ $bar['start'] }};span:{{ $bar['span'] }};lane:{{ $bar['lane'] }}" title="{{ $bar['tooltip'] }}" wire:click="openEditor({{ $bar['id'] }})">
                                <span class="timeline__crews">{{ $bar['crews'] }}</span><span>{{ $bar['client'] }} · {{ $bar['city'] }}</span>
                            </button>
                        @endforeach
                    </div>

                    @if ($timeline['absenceRows'] !== [])
                        <div class="timeline__absences">
                            <div class="timeline__absences-title">{{ __('planning.absences_title', ['agency' => $agency->name]) }}</div>
                            <div class="timeline__absences-body" data-vars="rows:{{ count($timeline['absenceRows']) }}">
                                @if ($timeline['todayIndex'] !== null)
                                    <div class="timeline__today" data-vars="start:{{ $timeline['todayIndex'] }}"></div>
                                @endif
                                @foreach ($timeline['absenceRows'] as $row => $absence)
                                    @foreach ($absence['bars'] as $bar)
                                        <div class="timeline__absence timeline__absence--{{ $bar['type'] }}" data-vars="start:{{ $bar['start'] }};span:{{ $bar['span'] }};row:{{ $row }}" title="{{ $bar['tooltip'] }}">@if ($bar['fits']){{ $bar['name'] }}@endif</div>
                                        @unless ($bar['fits'])
                                            <div class="timeline__absence-label" data-vars="start:{{ $bar['start'] + $bar['span'] }};row:{{ $row }}">{{ $bar['name'] }}</div>
                                        @endunless
                                    @endforeach
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            <div class="legend">
                @foreach (\App\Enums\WorkSiteStatus::cases() as $option)
                    <span class="legend__item"><span class="legend__swatch status-pill--{{ $option->value }}"></span>{{ $option->label() }}</span>
                @endforeach
                <span class="legend__item"><span class="legend__swatch legend__swatch--overbooked"></span>{{ __('planning.legend_overbooked') }}</span>
                @if ($timeline['absenceRows'] !== [])
                    @foreach (['absent', 'leave', 'sick'] as $type)
                        <span class="legend__item"><span class="legend__swatch timeline__absence--{{ $type }}"></span>{{ __('planning.legend_absences.'.$type) }}</span>
                    @endforeach
                @endif
            </div>
        @endif
    </div>

    <livewire:work-sites.work-site-editor />
</div>
