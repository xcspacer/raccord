@php
    $slug = $client->portalSlug();
    $box = $detail['box'];
    $lightbox = [];
    foreach ($detail['groups'] as $group) {
        foreach ($group['photos'] as $photo) {
            $lightbox[] = [
                'full' => route('portal.photos', [$slug, $photo['id'], 'full']),
                'download' => route('portal.photos', [$slug, $photo['id'], 'full', 'download' => 1]),
                'caption' => $group['name'].' · '.$charts->time($photo['at'], $now),
            ];
        }
    }
    $photoIndex = 0;
@endphp

<div class="portal-veil" wire:click="closeDrawer"></div>
<aside class="portal-drawer" aria-label="{{ $detail['name'] }}" x-data="portalLightbox" data-photos="{{ json_encode($lightbox, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}" wire:key="drawer-{{ $detail['type'] }}-{{ $detail['id'] }}">
    <div class="portal-drawer__head">
        <div>
            <h2>{{ $detail['name'] }}</h2>
            <div class="portal-drawer__sub">
                {{ $current['cabinet'] }} ·
                @if ($box)
                    {{ __('client_portal.kind.'.$box->kind->value) }}@if ($box->type) · {{ $box->type }}@endif · {{ __('client_portal.support.'.$detail['support']) }}@if ($box->support_number) {{ $box->support_number }}@endif
                @else
                    {{ __('client_portal.drawer.point') }}
                @endif
            </div>
        </div>
        <button type="button" class="portal-drawer__close" wire:click="closeDrawer" aria-label="{{ __('client_portal.drawer.close') }}">✕</button>
    </div>

    <div class="portal-drawer__body">
        @if ($box)
            <div class="portal-facts">
                <div class="portal-fact">
                    <small>{{ __('client_portal.drawer.status') }}</small>
                    <span class="portal-pill portal-pill--{{ $detail['status'] }}">{{ __('client_portal.status.'.$detail['status']) }}</span>
                    @if ($detail['reason'] !== '')<div class="portal-meta">{{ $detail['reason'] }}</div>@endif
                </div>
                <div class="portal-fact">
                    <small>{{ __('client_portal.drawer.progress') }}</small>
                    <b>{{ $detail['percent'] }}%</b>
                    @include('livewire.client-portal.partials.progress', ['percent' => $detail['percent']])
                </div>
                <div class="portal-fact">
                    <small>{{ __('client_portal.drawer.cable') }}</small>
                    <b>{{ $box->fibre_type?->label() ?? '—' }}</b>
                </div>
                <div class="portal-fact">
                    <small>{{ __('client_portal.drawer.location') }}</small>
                    @php($latitude = $box->confirmed_latitude ?? $box->latitude)
                    @php($longitude = $box->confirmed_longitude ?? $box->longitude)
                    @if ($latitude !== null && $longitude !== null)
                        <a href="https://www.google.com/maps?q={{ $latitude }},{{ $longitude }}" target="_blank" rel="noopener"><b>{{ __('client_portal.drawer.map') }}</b></a>
                    @else
                        <b>—</b>
                    @endif
                    @if ($box->address)<div class="portal-meta">{{ $box->address }}</div>@endif
                </div>
            </div>
        @endif

        <div class="portal-drawer__section portal-drawer__actions">
            @if ($detail['pdf'])
                <a class="portal-btn portal-btn--small" href="{{ route('portal.pdf', [$slug, $detail['type'], $detail['id']]) }}" target="_blank" rel="noopener">📄 {{ __('client_portal.drawer.open_pdf') }}</a>
                <a class="portal-btn portal-btn--ghost portal-btn--small" href="{{ route('portal.pdf', [$slug, $detail['type'], $detail['id'], 'download' => 1]) }}">⬇ {{ __('client_portal.drawer.download_pdf') }}</a>
            @else
                <span class="portal-meta">{{ __('client_portal.drawer.no_pdf') }}</span>
            @endif
            @unless ($detail['hidden'])
                <form method="POST" action="{{ route('portal.zip', $slug) }}">
                    @csrf
                    <input type="hidden" name="items[]" value="{{ $detail['type'] }}:{{ $detail['id'] }}">
                    <button type="submit" class="portal-btn portal-btn--ghost portal-btn--small">⬇ {{ __('client_portal.drawer.zip') }}</button>
                </form>
            @endunless
        </div>

        @if ($box && ($box->note_before || $box->note_route || $box->note_tco))
            <div class="portal-drawer__section">
                <h4>{{ __('client_portal.drawer.comments') }}</h4>
                @foreach (['before' => $box->note_before, 'route' => $box->note_route, 'tco' => $box->note_tco] as $noteKey => $note)
                    @if ($note)
                        <div class="portal-note"><small>{{ __('client_portal.drawer.notes.'.$noteKey) }}</small>{{ $note }}</div>
                    @endif
                @endforeach
            </div>
        @elseif ($detail['point'] && $detail['point']->note)
            <div class="portal-drawer__section">
                <h4>{{ __('client_portal.drawer.note') }}</h4>
                <div class="portal-note">{{ $detail['point']->note }}</div>
            </div>
        @endif

        @if ($detail['report'] !== [])
            <div class="portal-drawer__section">
                <h4>{{ __('client_portal.drawer.report') }}</h4>
                <ul class="portal-report">
                    @foreach ($detail['report'] as $item)
                        <li>{{ $item['label'] }}@if ($item['qty'] > 1) <b>× {{ $item['qty'] }}</b>@endif</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="portal-drawer__section">
            <h4>{{ __('client_portal.drawer.photos', ['count' => count($lightbox)]) }}</h4>
            @forelse ($detail['groups'] as $group)
                <div class="portal-group">
                    <h5>{{ $group['name'] }} <span>· {{ count($group['photos']) }}</span></h5>
                    <div class="portal-photos">
                        @foreach ($group['photos'] as $photo)
                            <button type="button" class="portal-photo" x-on:click="show({{ $photoIndex++ }})">
                                <img loading="lazy" src="{{ route('portal.photos', [$slug, $photo['id'], 'thumb']) }}" alt="">
                                <span>{{ $charts->shortTime($photo['at'], $now) }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>
            @empty
                <div class="portal-empty">{{ $detail['hidden'] ? __('client_portal.drawer.photos_after_validation') : __('client_portal.drawer.no_photos') }}</div>
            @endforelse
        </div>

        <div class="portal-drawer__section">
            <h4>{{ __('client_portal.notes.on_folder') }}</h4>
            @php($folderRemarks = $remarks->filter(fn ($remark) => $detail['type'] === 'box' ? $remark->audit_box_id === $detail['id'] : $remark->audit_control_point_id === $detail['id']))
            @forelse ($folderRemarks as $remark)
                @include('livewire.client-portal.partials.remark')
            @empty
                <div class="portal-meta portal-drawer__none">{{ __('client_portal.notes.none') }}</div>
            @endforelse
            <form class="portal-remark" wire:submit="sendDrawerNote">
                <b class="portal-remark__new">📝 {{ __('client_portal.notes.new_on_folder') }}</b>
                <textarea class="portal-textarea" rows="3" maxlength="2000" wire:model="drawerNote" placeholder="{{ __('client_portal.notes.folder_placeholder', ['company' => $company]) }}"></textarea>
                @error('drawerNote') <p class="portal-field__error">{{ $message }}</p> @enderror
                <div class="portal-remark__actions"><button type="submit" class="portal-btn">{{ __('client_portal.notes.send_to', ['company' => $company]) }}</button></div>
            </form>
        </div>
    </div>

    <div class="portal-lightbox" x-show="isOpen" x-cloak>
        <button type="button" class="portal-lightbox__close" x-on:click="close" aria-label="{{ __('client_portal.drawer.close') }}">✕</button>
        <button type="button" class="portal-lightbox__previous" x-on:click="previous" aria-label="{{ __('client_portal.drawer.previous') }}">‹</button>
        <img x-bind:src="src" alt="">
        <div class="portal-lightbox__caption"><span x-text="caption"></span> · <a x-bind:href="download">{{ __('client_portal.drawer.download') }}</a></div>
        <button type="button" class="portal-lightbox__next" x-on:click="next" aria-label="{{ __('client_portal.drawer.next') }}">›</button>
    </div>
</aside>
