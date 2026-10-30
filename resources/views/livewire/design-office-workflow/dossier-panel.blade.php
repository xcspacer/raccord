<section class="settings-card be-dossier" @if ($inProgress) wire:poll.15s.visible @endif>
    <h2 class="settings-card__title">{{ __('design_office_dossier.panel.title') }}</h2>
    <p class="field__hint">{{ __('design_office_dossier.panel.hint') }}</p>

    @error('dossier') <div class="field-warning" role="alert">{{ $message }}</div> @enderror

    @if ($diskLow)
        <div class="field-warning be-dossier__disk" role="alert">{{ __('design_office_dossier.panel.disk_low', ['free' => $diskFree, 'minimum' => $diskMinimum]) }}</div>
    @endif

    @if ($versions->isEmpty())
        <div class="empty-state">{{ __('design_office_dossier.panel.empty') }}</div>
    @else
        <div class="be-dossier__list">
            @foreach ($versions as $version)
                <article wire:key="dossier-version-{{ $version->id }}" class="be-dossier__version be-dossier__version--{{ $version->status->value }}">
                    <div class="be-dossier__main">
                        <div class="be-dossier__title">
                            <strong>{{ __('design_office_dossier.panel.version', ['number' => $version->version_number]) }}</strong>
                            <span class="be-dossier__kind">{{ $version->kind->label() }}</span>
                            <span class="be-dossier__status be-dossier__status--{{ $version->status->value }}">{{ $version->status->label() }}</span>
                        </div>
                        <div class="be-dossier__meta">
                            {{ __('design_office_dossier.panel.requested', ['date' => $version->created_at->timezone(config('platform.timezone'))->format('d/m/Y H:i')]) }}
                            · {{ $version->authorizer ? __('design_office_dossier.panel.authorized_by', ['name' => $version->authorizer->name]) : __('design_office_dossier.panel.automatic') }}
                            @if ($version->locale)
                                · {{ __('design_office_dossier.panel.language', ['language' => __('platform.languages.'.$version->locale)]) }}
                            @endif
                            @if ($version->published_at)
                                · {{ __('design_office_dossier.panel.published', ['date' => $version->published_at->timezone(config('platform.timezone'))->format('d/m/Y H:i')]) }}
                            @endif
                        </div>
                        @if ($version->kind->value === 'update')
                            <p class="be-dossier__text">{{ $version->reason ?? __('design_office_dossier.reasons.automatic_update') }}</p>
                        @endif

                        @if ($version->isPublished())
                            <div class="be-dossier__meta">
                                {{ __('design_office_dossier.panel.files', ['count' => $version->files_count, 'size' => $sizes[$version->id] ?? '']) }}
                                @if ($version->changes)
                                    · {{ __('design_office_dossier.panel.changes', ['added' => count($version->changes['added']), 'replaced' => count($version->changes['replaced']), 'removed' => count($version->changes['removed']), 'previous' => $version->changes['previous']]) }}
                                @endif
                            </div>
                        @elseif ($version->status->isInProgress())
                            <p class="be-dossier__text">{{ $version->status->value === 'pending' ? __('design_office_dossier.panel.waiting') : __('design_office_dossier.panel.generating') }}</p>
                        @else
                            <p class="be-dossier__error">{{ __('design_office_dossier.errors.'.($version->error ?? 'unexpected')) }}</p>
                        @endif

                        @if ($version->checks && ! $version->isPublished())
                            <ul class="be-dossier__checks">
                                @foreach ($version->checks as $check)
                                    <li wire:key="dossier-check-{{ $version->id }}-{{ $check['code'] }}" @class(['be-dossier__check', 'be-dossier__check--failed' => ! $check['ok'] && $check['blocking'], 'be-dossier__check--warning' => ! $check['ok'] && ! $check['blocking']])>
                                        {{ $check['ok'] ? '✓' : '✗' }} {{ __('design_office_dossier.checks.'.$check['code']) }}@if (! $check['ok'] && $check['count']) ({{ $check['count'] }})@endif
                                    </li>
                                @endforeach
                            </ul>
                        @elseif ($version->checks)
                            @foreach (array_filter($version->checks, fn ($check) => ! $check['ok']) as $check)
                                <p class="be-dossier__warning" wire:key="dossier-warning-{{ $version->id }}-{{ $check['code'] }}">⚠ {{ __('design_office_dossier.checks.'.$check['code']) }}@if ($check['count']) ({{ $check['count'] }})@endif</p>
                            @endforeach
                        @endif
                    </div>

                    <div class="be-dossier__actions">
                        @if ($version->isPublished())
                            <a class="btn btn--primary btn--small" href="{{ route('design-office.dossiers.full', $version) }}">{{ __('design_office_dossier.panel.download_full') }}</a>
                            @if ($version->changes)
                                <a class="btn btn--ghost btn--small" href="{{ route('design-office.dossiers.changes', $version) }}">{{ __('design_office_dossier.panel.download_changes') }}</a>
                            @endif
                        @elseif ($version->status->value === 'failed' && $isSupervisor && $version->version_number === $versions->max('version_number'))
                            <button type="button" class="btn btn--ghost btn--small" wire:click="relaunch({{ $version->id }})" wire:confirm="{{ __('design_office_dossier.panel.confirm_relaunch') }}">↻ {{ __('design_office_dossier.panel.relaunch') }}</button>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</section>
