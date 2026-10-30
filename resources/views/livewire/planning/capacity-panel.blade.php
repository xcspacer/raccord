<div wire:poll.60s.visible @class(['capacity-panel', 'capacity-panel--menu' => $inMenu, 'capacity-panel--header' => $part === 'box'])>
    @if ($visible && $showBox)
        <div @class(['capacity-box', 'capacity-box--over' => $ranges !== []])>
            @if ($inMenu)
                <span class="capacity-box__icon"><x-navigation.icon name="users" /></span>
            @endif
            <label for="capacity-{{ $this->getId() }}">{{ $inMenu ? rtrim(__('planning.capacity'), ' :') : __('planning.capacity') }}</label>
            <input type="number" id="capacity-{{ $this->getId() }}" min="1" max="255" wire:model.live.debounce.400ms="capacity" @disabled(! $canEdit)>
            <span class="capacity-box__flag">⚠ {{ __('planning.exceeded') }}</span>
        </div>
    @endif

    @if ($visible && $showAlert)
        @if ($ranges !== [])
            <div class="alert-band">
                <span class="alert-band__tag">{{ __('planning.overbooking') }}</span>
                <span>{{ __('planning.overbooking_text', ['capacity' => $agency->daily_crew_capacity, 'periods' => collect($ranges)->take(4)->map(fn ($range) => $range[0]->eq($range[1]) ? $range[0]->translatedFormat('d M') : $range[0]->translatedFormat('d M').' → '.$range[1]->translatedFormat('d M'))->implode(', ').(count($ranges) > 4 ? '…' : '')]) }}</span>
            </div>
        @endif
    @endif
</div>
