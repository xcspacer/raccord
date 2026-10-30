<x-slot:header>
    <livewire:planning.capacity-panel part="box" />
</x-slot:header>

<div wire:poll.60s.visible @if ($openOnLoad) wire:init="openEditor" @endif>
    <livewire:planning.capacity-panel part="alert" />

    @if (! $canList)
        <div class="empty-state">
            <button type="button" class="btn btn--primary" wire:click="openEditor">+ {{ __('work_sites.new') }}</button>
        </div>
    @else
        <div class="toolbar">
            <div class="toolbar__filters">
                <input class="search-input" type="search" wire:model.live.debounce.250ms="search" placeholder="{{ __('work_sites.search') }}" aria-label="{{ __('work_sites.search') }}">
                <select class="select-filter" wire:model.live="status" aria-label="{{ __('work_sites.fields.status') }}">
                    <option value="">{{ __('work_sites.all_statuses') }}</option>
                    @foreach ($statuses as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </select>
            </div>

            @if ($canCreate)
                <button type="button" class="btn btn--primary toolbar__in-menu" wire:click="openEditor" aria-label="{{ __('work_sites.new') }}">+ {{ __('work_sites.new') }}</button>
            @endif
        </div>

        @if ($sites->isEmpty())
            <div class="empty-state">
                {{ __('work_sites.empty') }}
                @if ($canCreate)
                    <br><button type="button" class="btn btn--primary" wire:click="openEditor">+ {{ __('work_sites.new') }}</button>
                @endif
            </div>
        @else
            <table class="data-table">
                <thead>
                    <tr>
                        <th>{{ __('work_sites.fields.client') }}</th>
                        <th>{{ __('work_sites.fields.city') }}</th>
                        <th>{{ __('work_sites.fields.agency') }}</th>
                        <th>{{ __('work_sites.columns.starts_on') }}</th>
                        <th>{{ __('work_sites.columns.ends_on') }}</th>
                        <th>{{ __('work_sites.columns.crews') }}</th>
                        <th>{{ __('work_sites.fields.status') }}</th>
                        <th>{{ __('work_sites.columns.manager') }}</th>
                        <th>{{ __('work_sites.columns.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sites as $site)
                        <tr wire:key="site-{{ $site->id }}">
                            <td data-label="{{ __('work_sites.fields.client') }}">{{ $site->client?->name }}</td>
                            <td data-label="{{ __('work_sites.fields.city') }}">{{ $site->city }}</td>
                            <td data-label="{{ __('work_sites.fields.agency') }}">{{ $site->agency?->name ?? '—' }}</td>
                            <td data-label="{{ __('work_sites.columns.starts_on') }}">{{ $site->starts_on->translatedFormat('D d M') }}</td>
                            <td data-label="{{ __('work_sites.columns.ends_on') }}">{{ $site->ends_on->translatedFormat('D d M') }}</td>
                            <td data-label="{{ __('work_sites.columns.crews') }}">{{ $site->crew_count }}@if ($capacity->touchesOverbookedDay($site, $overbooked))<span class="data-table__warn" title="{{ __('work_sites.capacity_exceeded_period') }}">⚠</span>@endif</td>
                            <td data-label="{{ __('work_sites.fields.status') }}"><span class="status-pill status-pill--{{ $site->status->value }}">{{ $site->status->label() }}</span></td>
                            <td data-label="{{ __('work_sites.columns.manager') }}">{{ $site->site_manager ?: '—' }}</td>
                            <td data-label="{{ __('work_sites.columns.actions') }}">
                                <div class="data-table__actions">
                                    @can('update', $site)
                                        <button type="button" class="btn btn--ghost" wire:click="openEditor({{ $site->id }})">{{ __('work_sites.edit') }}</button>
                                    @endcan
                                    @can('delete', $site)
                                        <button type="button" class="btn btn--ghost btn--danger" wire:click="delete({{ $site->id }})" wire:confirm="{{ __('work_sites.confirm_delete') }}">{{ __('work_sites.delete') }}</button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endif

    <livewire:work-sites.work-site-editor />
</div>
