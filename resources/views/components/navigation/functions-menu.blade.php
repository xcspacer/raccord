@props(['groups', 'agencies' => null, 'currentAgencyId' => null, 'state' => false])

<section class="fn-menu">
    <div class="section-label">{{ __('app.functions') }}</div>

    @if ($agencies && $agencies->count() > 1)
        <div class="fn-menu__state">
            <div class="segmented" role="group" aria-label="{{ __('app.agency') }}">
                @foreach ($agencies as $agency)
                    <form method="POST" action="{{ route('agency.switch', $agency) }}">
                        @csrf
                        <button type="submit" @class(['segmented__option', 'is-active' => $agency->id === $currentAgencyId])>{{ $agency->name }}</button>
                    </form>
                @endforeach
            </div>
        </div>
    @endif

    @if ($state)
        <livewire:planning.capacity-panel :in-menu="true" />
    @endif

    @foreach ($groups as $group)
        <div class="fn-group fn-group--{{ $group->key }}">
            <div class="fn-group__title"><i></i>{{ $group->title }}</div>

            @foreach ($group->items as $item)
                <a href="{{ route($item->route) }}" @class(['fn-item', 'is-current' => request()->routeIs($item->route)]) wire:navigate>
                    <span class="fn-item__icon"><x-navigation.icon :name="$item->icon" /></span>
                    <span class="fn-item__text">
                        <span class="fn-item__label">{{ $item->label }}<span class="fn-item__badge" data-menu-badge="{{ $item->key }}" hidden></span></span>
                        <span class="fn-item__description">{{ $item->description }}</span>
                    </span>
                    <span class="fn-item__go"></span>
                </a>
            @endforeach
        </div>
    @endforeach
</section>
