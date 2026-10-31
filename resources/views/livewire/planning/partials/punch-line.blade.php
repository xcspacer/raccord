@php
    $mapsLink = fn ($latitude, $longitude) => str_replace([':lat', ':lng'], [(string) (float) $latitude, (string) (float) $longitude], config('platform.maps_link'));
    $isToday = $day->isSameDay(now(config('platform.timezone')));
@endphp

<div class="punch-line">
    <span class="punch-line__in">
        <span title="{{ __('day_punch.pin.start') }}">▶ {{ $punch->startLocal() }}</span>
        @if ($punch->start_latitude !== null)
            <a class="punch-line__pin" href="{{ $mapsLink($punch->start_latitude, $punch->start_longitude) }}" target="_blank" rel="noopener noreferrer" wire:click.stop title="{{ __('day_punch.pin.open_map') }}">📍</a>
            @if ($flagsAccuracy && $punch->hasImpreciseStart())
                <span class="punch-line__imprecise" title="{{ __('day_punch.pin.imprecise', ['metres' => $punch->start_accuracy]) }}">⚠</span>
            @endif
        @elseif ($punch->location_purged_at === null)
            <span class="punch-line__pin punch-line__pin--none" title="{{ __('day_punch.pin.no_position') }}">📍✗</span>
        @endif
    </span>

    @if ($punch->end_at)
        <span class="punch-line__out">
            <span title="{{ $punch->end_declared ? __('day_punch.pin.declared') : __('day_punch.pin.end') }}">■ {{ $punch->endLocal() }}@if ($punch->end_declared)*@endif</span>
            @if (! $punch->end_declared && $punch->end_latitude !== null)
                <a class="punch-line__pin" href="{{ $mapsLink($punch->end_latitude, $punch->end_longitude) }}" target="_blank" rel="noopener noreferrer" wire:click.stop title="{{ __('day_punch.pin.open_map') }}">📍</a>
                @if ($flagsAccuracy && $punch->hasImpreciseEnd())
                    <span class="punch-line__imprecise" title="{{ __('day_punch.pin.imprecise', ['metres' => $punch->end_accuracy]) }}">⚠</span>
                @endif
            @elseif (! $punch->end_declared && $punch->location_purged_at === null)
                <span class="punch-line__pin punch-line__pin--none" title="{{ __('day_punch.pin.no_position') }}">📍✗</span>
            @endif
        </span>
    @elseif ($isToday)
        <span class="punch-line__out punch-line__out--running">{{ __('day_punch.pin.running') }}</span>
    @else
        <span class="punch-line__out punch-line__out--missing" title="{{ __('day_punch.pin.missing_end') }}">■ —</span>
    @endif
</div>
