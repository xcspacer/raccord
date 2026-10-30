@php
    $fileUrl = fn (string $path, bool $thumb = false) => route('media-library.audits.file', array_filter(['path' => $path, 'variant' => $thumb ? 'thumb' : null]));
    $time = fn ($at) => $at === null ? '—' : ($at->setTimezone($now->getTimezone())->isSameDay($now) ? $at->setTimezone($now->getTimezone())->format('H:i') : $at->setTimezone($now->getTimezone())->format('d/m H:i'));
@endphp

<div class="media-library">
    <nav class="media-library__crumbs" aria-label="{{ __('media_library.deliverables.title') }}">
        <a class="media-library__crumb" href="{{ route('media-library') }}" wire:navigate>{{ __('media_library.title') }}</a>
        <span class="media-library__sep">/</span>
        @if ($path === '')
            <span class="media-library__crumb is-current">{{ __('media_library.deliverables.title') }}</span>
        @else
            <button type="button" class="media-library__crumb" wire:click="go('')">{{ __('media_library.deliverables.title') }}</button>
        @endif
        @foreach ($crumbs as $crumb)
            <span class="media-library__sep">/</span>
            @if ($crumb['path'] === null)
                <span class="media-library__crumb is-current">{{ $crumb['label'] }}</span>
            @else
                <button type="button" class="media-library__crumb" wire:click="go({{ json_encode($crumb['path'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }})">{{ $crumb['label'] }}</button>
            @endif
        @endforeach
    </nav>

    @if ($path === '')
        <div class="deliverables__tabs">
            <button type="button" @class(['is-on' => $tab === 'activity']) wire:click="showTab('activity')">🕒 {{ __('media_library.deliverables.activity') }}</button>
            <button type="button" @class(['is-on' => $tab === 'folders']) wire:click="showTab('folders')">📁 {{ __('media_library.deliverables.folders') }}</button>
        </div>
    @endif

    @if ($path === '' && $tab === 'activity')
        <div class="deliverables__bar">
            <div class="deliverables__chips">
                @foreach (\App\Livewire\MediaLibrary\AuditDeliverablesPage::PERIODS as $option)
                    <button type="button" @class(['deliverables__chip', 'is-on' => $period === $option]) wire:click="choosePeriod('{{ $option }}')">{{ __('media_library.deliverables.periods.'.$option) }}</button>
                @endforeach
                <input type="date" @class(['deliverables__search', 'deliverables__date', 'is-on' => $period === 'day']) wire:model.live="day" title="{{ __('media_library.deliverables.pick_day') }}" aria-label="{{ __('media_library.deliverables.pick_day') }}">
            </div>
            <div class="deliverables__chips">
                @foreach (\App\Livewire\MediaLibrary\AuditDeliverablesPage::TYPES as $option)
                    <button type="button" @class(['deliverables__chip', 'is-on' => $type === $option]) wire:click="chooseType('{{ $option }}')">{{ __('media_library.deliverables.types.'.$option) }}</button>
                @endforeach
            </div>
            <input type="search" class="deliverables__search" wire:model.live.debounce.250ms="search" placeholder="{{ __('media_library.deliverables.search') }}" aria-label="{{ __('media_library.deliverables.search') }}">
        </div>

        @forelse ($feed as $date => $rows)
            @php($day = \Carbon\CarbonImmutable::parse($date, $now->getTimezone()))
            <h3 class="deliverables__day">{{ $day->isSameDay($now) ? __('media_library.deliverables.today') : ($day->isSameDay($now->subDay()) ? __('media_library.deliverables.yesterday') : $day->translatedFormat('l j F')) }}</h3>
            @foreach ($rows as $row)
                <div class="deliverables__row" wire:key="feed-{{ md5($row['path']) }}">
                    <input type="checkbox" value="{{ $row['path'] }}" wire:model.live="selected" aria-label="{{ $row['name'] }}">
                    <button type="button" class="deliverables__main" wire:click="go({{ json_encode($row['path'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }})">
                        <b>{{ $row['name'] }}</b>
                        <span class="deliverables__meta">{{ $row['cabinet'] }} · {{ $row['group'] }}</span>
                    </button>
                    <span class="deliverables__meta">📷 {{ $row['photos'] }}@if ($row['pdfs']) · 📄 {{ $row['pdfs'] }}@endif · {{ $time($row['latest']) }}@if ($row['authors'] !== []) · 👤 {{ implode(', ', array_slice($row['authors'], 0, 2)) }}@if (count($row['authors']) > 2) +{{ count($row['authors']) - 2 }}@endif @endif</span>
                </div>
            @endforeach
        @empty
            <div class="empty-state">
                {{ __('media_library.deliverables.no_activity') }}
                @if ($period !== 'all')
                    <button type="button" class="btn btn--ghost btn--small" wire:click="choosePeriod('all')">{{ __('media_library.deliverables.periods.all') }}</button>
                @endif
            </div>
        @endforelse
    @elseif ($unit)
        @php($photoCount = $unitFiles->flatten(1)->where('photo', true)->count())
        @php($authors = $unitFiles->flatten(1)->pluck('author')->filter()->unique()->values())
        <p class="deliverables__hint">📷 {{ $photoCount }} · {{ $time($unitFiles->flatten(1)->max('at')) }}@if ($authors->isNotEmpty()) · 👤 {{ $authors->implode(', ') }}@endif</p>
        <div class="deliverables__actions">
            <form method="POST" action="{{ route('media-library.audits.zip') }}">
                @csrf
                <input type="hidden" name="items[]" value="{{ $unit['path'] }}">
                <button type="submit" class="btn btn--ghost btn--small">⬇ {{ __('media_library.deliverables.download_folder') }}</button>
            </form>
        </div>
        @forelse ($unitFiles as $subfolder => $files)
            <h4 class="deliverables__group">{{ $subfolder !== '' ? $subfolder : $unit['name'] }} <span>· {{ $files->count() }}</span></h4>
            @foreach ($files->where('photo', false) as $file)
                <a class="deliverables__file" href="{{ $fileUrl($file['path']) }}" target="_blank" rel="noopener" data-file-view data-file-name="{{ basename($file['path']) }}">{{ $file['file']->extension === 'pdf' ? '📄' : '📎' }} {{ basename($file['path']) }}</a>
            @endforeach
            @if ($files->where('photo', true)->isNotEmpty())
                <div class="media-library__photos" data-file-group>
                    @foreach ($files->where('photo', true) as $file)
                        <a class="media-library__photo" href="{{ $fileUrl($file['path']) }}" target="_blank" rel="noreferrer" title="{{ basename($file['path']) }}" data-file-view data-file-name="{{ basename($file['path']) }}">
                            <img src="{{ $fileUrl($file['path'], true) }}" loading="lazy" alt="">
                            <span class="media-library__name">{{ $time($file['at']) }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        @empty
            <div class="empty-state">{{ __('media_library.no_files') }}</div>
        @endforelse
    @else
        <div class="deliverables__bar">
            <label class="deliverables__check"><input type="checkbox" wire:model.live="hideEmpty"> {{ __('media_library.deliverables.hide_empty') }}</label>
            <select class="deliverables__search deliverables__sort" wire:model.live="sort" aria-label="{{ __('media_library.deliverables.sort') }}">
                <option value="recent">{{ __('media_library.deliverables.sort_recent') }}</option>
                <option value="name">{{ __('media_library.deliverables.sort_name') }}</option>
            </select>
            @if ($folders->isNotEmpty())
                <button type="button" class="btn btn--ghost btn--small" wire:click="selectAll({{ json_encode($folders->pluck('path')->all(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}, {{ $folders->pluck('path')->diff($selected)->isEmpty() ? 'false' : 'true' }})">{{ $folders->pluck('path')->diff($selected)->isEmpty() ? __('media_library.deliverables.unselect_all') : __('media_library.deliverables.select_all') }}</button>
            @endif
            @if ($path !== '')
                <form method="POST" action="{{ route('media-library.audits.zip') }}">
                    @csrf
                    <input type="hidden" name="items[]" value="{{ $path }}">
                    <button type="submit" class="btn btn--ghost btn--small">⬇ {{ __('media_library.deliverables.download_folder') }}</button>
                </form>
            @endif
        </div>

        @foreach ($levelFiles as $file)
            <a class="deliverables__file" href="{{ $fileUrl($file['path']) }}" target="_blank" rel="noopener" data-file-view data-file-name="{{ basename($file['path']) }}">{{ $file['file']->extension === 'pdf' ? '📄' : '📎' }} {{ basename($file['path']) }}</a>
        @endforeach

        <div class="media-library__grid">
            @foreach ($folders as $item)
                <div class="media-library__folder" wire:key="dir-{{ md5($item['path']) }}">
                    <label class="media-library__check"><input type="checkbox" value="{{ $item['path'] }}" wire:model.live="selected" aria-label="{{ $item['name'] }}"></label>
                    <button type="button" class="media-library__open" wire:click="go({{ json_encode($item['path'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }})">📁 {{ $item['name'] }} <span class="media-library__count">📷 {{ $item['photos'] }}@if ($item['latest']) · {{ $time($item['latest']) }}@endif</span></button>
                </div>
            @endforeach
        </div>
        @if ($folders->isEmpty() && $levelFiles->isEmpty())
            <div class="empty-state">{{ __('media_library.no_folders') }}</div>
        @endif
    @endif

    @if ($selected !== [])
        <form class="deliverables__selection" method="POST" action="{{ route('media-library.audits.zip') }}">
            @csrf
            @foreach ($selected as $item)
                <input type="hidden" name="items[]" value="{{ $item }}">
            @endforeach
            <span>{{ __('media_library.selected', ['count' => count($selected)]) }}</span>
            <button type="button" class="btn btn--ghost btn--small" wire:click="clearSelection" aria-label="{{ __('media_library.deliverables.unselect_all') }}">✕</button>
            <button type="submit" class="btn btn--primary btn--small">⬇ {{ __('media_library.deliverables.download_selection') }}</button>
        </form>
    @endif
</div>
