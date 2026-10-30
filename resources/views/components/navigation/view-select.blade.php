@props(['groups', 'page'])

@php
    $keys = ['planning' => ['planning'], 'work-sites' => ['work_sites', 'create_work_site'], 'absences' => ['absences']][$page] ?? [];
    $items = collect($groups)->firstWhere('key', 'planning')?->items ?? [];
@endphp

@if ($keys !== [] && count($items) > 1)
    <div class="view-select">
        <select class="select-filter" data-view-select aria-label="{{ __('app.views') }}">
            @foreach ($items as $item)
                <option value="{{ route($item->route) }}" @selected(in_array($item->key, $keys, true))>{{ $item->label }}</option>
            @endforeach
        </select>
    </div>
@endif
