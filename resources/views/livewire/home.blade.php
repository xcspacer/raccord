<div class="home">
    <div class="home__head">
        <h2 class="home__title">{{ __('app.home') }}</h2>
        <div class="map__subtitle" data-map-subtitle></div>
    </div>

    @if ($showPlanningPanels)
        <section class="map" wire:ignore data-work-site-map data-url="{{ route('map.work-sites') }}" data-labels="{{ json_encode($mapLabels, JSON_UNESCAPED_UNICODE) }}"></section>

        <livewire:work-sites.work-site-editor />
    @endif

    <div class="home__functions">
        <x-navigation.functions-menu :state="$showPlanningPanels" :groups="$menuGroups" :agencies="$switchableAgencies" :current-agency-id="$currentAgencyId" />
    </div>
</div>
