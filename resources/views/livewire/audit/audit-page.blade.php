<div class="audit" wire:poll.20s.visible="checkUpdates">
    <div class="audit__head">
        <a class="btn btn--ghost" href="{{ route('audits.'.($audit && $audit->effectiveMode() === \App\Enums\AuditMode::Optical ? 'optical' : 'full')) }}" wire:navigate>← {{ __('audit.back') }}</a>
        @include('livewire.audit.partials.sync-chip')
    </div>

    @error('audit') <div class="field-warning" role="alert">{{ $message }}</div> @enderror

    @if (! $audit || $configuring)
        @if ($isManager)
            <h1 class="audit__title">{{ $cabinet }}</h1>
            <p class="audit__muted">{{ $audit ? __('audit.configure_hint') : __('audit.not_configured_manager') }}</p>
            <livewire:audit.audit-configure :cabinet="$cabinet" :default-mode="$defaultMode" :key="'configure-'.$cabinet" />
            @if ($audit)<button type="button" class="btn btn--ghost" wire:click="$set('configuring', false)">{{ __('inventory.cancel') }}</button>@endif
        @else
            <div class="empty-state">{{ __('audit.not_configured_technician', ['cabinet' => $cabinet]) }}</div>
        @endif
    @else
        <h1 class="audit__title">{{ $audit->cabinet_key }}@if ($audit->sro) <span class="audit__sro">{{ $audit->sro }}</span>@endif <span class="audit__chip audit__chip--{{ $auditMode->value }}">{{ $auditMode->label() }}</span></h1>

        <div class="audit__toolbar">
            @if ($hasCabinetPosition)<button type="button" class="btn btn--ghost" wire:click="openWindow('navigate')">🧭 {{ __('audit.toolbar.navigate_cabinet') }}</button>@endif
            @if ($audit->synoptic_file_id)<a class="btn btn--ghost" href="{{ route('audits.files', ['cabinet' => $audit->cabinet_key, 'kind' => 'synoptic']) }}">📄 {{ __('audit.toolbar.synoptic') }}</a>@endif
            @if ($audit->kmz_file_id)<a class="btn btn--ghost" href="{{ route('audits.files', ['cabinet' => $audit->cabinet_key, 'kind' => 'map']) }}">🗺 {{ __('audit.toolbar.map') }}</a>@endif
            @foreach ($audit->plans->where('kind', \App\Enums\AuditPlanKind::Drawer) as $plan)
                <a class="btn btn--ghost" href="{{ $plan->file->extension === 'pdf' ? route('audits.files', ['cabinet' => $audit->cabinet_key, 'kind' => 'plan', 'plan' => $plan->id]) : route('audits.plans.show', ['cabinet' => $audit->cabinet_key, 'plan' => $plan->id]) }}">🗄 {{ __('audit.toolbar.drawer_plan') }}</a>
            @endforeach
            @if ($drawerRecheck)<a class="btn btn--ghost" href="{{ route('audits.drawer', ['cabinet' => $audit->cabinet_key]) }}" wire:navigate>🔧 {{ __('audit.toolbar.drawer_recheck') }}</a>@endif
            @if ($drawerAfterWorks)<a class="btn btn--ghost" href="{{ route('audits.files', ['cabinet' => $audit->cabinet_key, 'kind' => 'drawer']) }}" download>📥 {{ __('audit.toolbar.drawer_after_works') }}</a>@endif
            @if ($audit->plans->where('kind', \App\Enums\AuditPlanKind::Box)->isNotEmpty())
                <details class="audit__plans">
                    <summary class="btn btn--ghost">📐 {{ __('audit.toolbar.box_plans', ['count' => $audit->plans->where('kind', \App\Enums\AuditPlanKind::Box)->count()]) }}</summary>
                    @foreach ($audit->plans->where('kind', \App\Enums\AuditPlanKind::Box) as $plan)
                        <a class="audit__plan" href="{{ $plan->file->extension === 'pdf' ? route('audits.files', ['cabinet' => $audit->cabinet_key, 'kind' => 'plan', 'plan' => $plan->id]) : route('audits.plans.show', ['cabinet' => $audit->cabinet_key, 'plan' => $plan->id]) }}">{{ $plan->name }}</a>
                    @endforeach
                </details>
            @endif
            @if ($isManager && $deliverablesPath && $plansAfterWorks > 0)
                <form method="POST" action="{{ route('media-library.audits.zip') }}" class="audit__toolbar-form">
                    @csrf
                    <input type="hidden" name="items[]" value="{{ $deliverablesPath.'/'.__('audit.tree.plans_folder', ['cabinet' => $audit->cabinet_key]) }}">
                    <button type="submit" class="btn btn--ghost" title="{{ __('audit.toolbar.plans_after_works_hint') }}">📦 {{ __('audit.toolbar.plans_after_works', ['count' => $plansAfterWorks]) }}</button>
                </form>
            @endif
            @if ($isManager && ! $locked)<button type="button" class="btn btn--ghost" wire:click="$set('configuring', true)">⚙ {{ __('audit.configure_button') }}</button>@endif
            @if ($isManager && $auditMode === \App\Enums\AuditMode::Optical)
                <a class="btn btn--ghost" href="{{ route('audits.synthesis', ['cabinet' => $audit->cabinet_key]) }}">📊 {{ __('audit.synthesis.button') }}</a>
            @endif
            @if ($deliverablesPath)
                <a class="btn btn--ghost" href="{{ route('media-library.audits', ['path' => $deliverablesPath]) }}" wire:navigate>📁 {{ __('media_library.deliverables.title') }}</a>
            @endif
            @if ($closureLink)
                <a class="btn btn--ghost" href="{{ route('design-office.closure', ['pm' => $audit->cabinet_key]) }}" wire:navigate>🔒 {{ __('design_office_workflow.closure.title') }}</a>
            @endif
            @if ($audit->usesDesignOfficeWorkflow() && ! $audit->isClosed())
                @can('viewAny', \App\Models\AuditIntervention::class)
                    <a class="btn btn--ghost" href="{{ route('design-office.repairs', ['audit' => $audit->id]) }}" wire:navigate>🛠 {{ __('design_office_workflow.repairs.title') }}</a>
                @endcan
            @endif
            @if ($isManager && ! $locked)
                <button type="button" class="btn btn--ghost btn--danger" wire:click="askArchive">🗑 {{ __('audit.archive.button') }}</button>
            @endif
        </div>

        @if ($audit->usesDesignOfficeWorkflow() && $audit->isClosed())
            <div class="audit__banner audit__banner--ok">🔒 {{ __('design_office_workflow.closure.closed_by', ['name' => $audit->closer?->name ?? '—', 'date' => $audit->closed_at->timezone(config('platform.timezone'))->format('d/m/Y H:i')]) }}</div>
        @elseif ($audit->isValidated())
            <div class="audit__banner audit__banner--ok">✅ {{ __('audit.validated_by', ['name' => $audit->validated_by_name]) }}</div>
        @elseif ($audit->isFinished())
            <div class="audit__banner audit__banner--partial">⏳ {{ __('audit.finished_by', ['name' => $audit->finished_by_name]) }}</div>
        @endif

        @include('livewire.audit.partials.client-notes')

        <section class="audit__progress">
            <strong class="audit__percent">{{ $percent }}%</strong>
            <span class="audit__muted">{{ __('audit.boxes_done', ['done' => $boxesDone, 'total' => $boxesTotal]) }}</span>
            <div class="audit__bar" role="progressbar" aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100"><span class="audit__bar-fill" data-vars="width:{{ $percent }}%"></span></div>
        </section>

        @if ($presences->isNotEmpty())
            <div class="audit__whos">
                @foreach ($presences as $presence)
                    @php($presenceBox = $audit->boxes->firstWhere('id', $presence->audit_box_id)?->name)
                    <a class="audit__who audit__who--{{ $presence->state->value }}" wire:key="presence-{{ $presence->id ?? $presence->user_id }}" @if ($presenceBox) href="{{ route('audits.box', ['cabinet' => $audit->cabinet_key, 'name' => $presenceBox]) }}" @endif>{{ $presence->state === \App\Enums\AuditPresenceState::OnBox ? '📍' : '🚶' }} {{ $presence->user?->name }} → {{ $presenceBox }}</a>
                @endforeach
            </div>
        @endif

        <h2 class="audit__section">{{ __('audit.points_title') }}</h2>
        <div class="audit__points">
            @foreach ($points as $point)
                <a class="audit__point" href="{{ route('audits.point', ['cabinet' => $audit->cabinet_key, 'id' => $point->id]) }}" wire:key="point-{{ $point->id }}">
                    <strong class="audit__point-name">{{ $point->name }}</strong>
                    <span class="audit__muted">{{ $point->photos->count() > 0 ? '📷 '.$point->photos->count() : __('audit.no_photo') }}{{ $point->note ? ' · 📝' : '' }}</span>
                </a>
            @endforeach
        </div>
        @unless ($locked)
            <button type="button" class="btn btn--ghost audit__add-button" wire:click="openWindow('point')">＋ {{ __('audit.add_point') }}</button>
        @endunless

        <h2 class="audit__section">{{ __('audit.boxes') }} <span class="audit__count">{{ $boxesTotal }}</span></h2>
        <div class="audit__filters">
            <button type="button" @class(['audit__filter', 'is-active' => $kindFilter === '']) wire:click="$set('kindFilter', '')">{{ __('audit.all') }} <span>{{ $boxesTotal }}</span></button>
            @foreach ($kinds as $kind)
                <button type="button" @class(['audit__filter', 'is-active' => $kindFilter === $kind->value]) wire:click="$set('kindFilter', '{{ $kind->value }}')">{{ $kind->label() }} <span>{{ $kindCounts[$kind->value] ?? 0 }}</span></button>
            @endforeach
        </div>
        <div class="audit__filters">
            <button type="button" @class(['audit__filter', 'is-active' => $activeSupportFilter === '']) wire:click="$set('supportFilter', '')">{{ __('audit.all') }} <span>{{ $boxesTotal }}</span></button>
            @foreach ($supportFilters as $group => $icon)
                <button type="button" @class(['audit__filter', 'is-active' => $activeSupportFilter === $group]) wire:click="$set('supportFilter', '{{ $group }}')">{{ $icon }}{{ __('audit.support_filters.'.$group) }} <span>{{ $supportCounts[$group] ?? 0 }}</span></button>
            @endforeach
        </div>
        <input class="field__input audit__search" type="search" wire:model.live.debounce.200ms="search" placeholder="{{ __('audit.search') }}" aria-label="{{ __('audit.search') }}">
        <div class="audit__nearby" x-data="auditLocate" data-denied="{{ __('audit.location_denied') }}">
            <button type="button" @class(['audit__filter', 'is-active' => $nearestFirst]) wire:click="toggleNearest">📍 {{ __('audit.nearest_first') }}</button>
            <span class="audit__muted" x-show="denied" x-text="deniedText"></span>
        </div>

        <div class="audit__boxes">
            @forelse ($groups as $group)
                @if ($group['kind'])
                    <div class="audit__group" wire:key="group-{{ $group['kind']->value }}">{{ $group['kind']->label() }} <span>{{ $group['rows']->count() }}</span></div>
                @endif
                @foreach ($group['rows'] as $row)
                    @php($box = $row['box'])
                    <a class="audit__box" href="{{ route('audits.box', ['cabinet' => $audit->cabinet_key, 'name' => $box->name]) }}" wire:key="box-{{ $box->id }}">
                        <span class="audit__box-head">
                            <span class="audit__box-name">{{ $box->name }}@if (in_array($box->id, $newBoxes, true)) <span class="audit__new">● {{ __('audit.new_mark') }}</span>@endif @if ($row['unanswered'])<span class="audit__new" title="{{ __('audit.office_message') }}">📣</span>@endif</span>
                            @if ($row['distance'] !== null)<span class="audit__distance">📍 {{ $row['distance'] < 1000 ? __('app.units.metres', ['value' => $row['distance']]) : __('app.units.kilometres', ['value' => \Illuminate\Support\Number::format($row['distance'] / 1000, precision: 1, locale: app()->getLocale())]) }}</span>@endif
                            @if ($box->isReturned())
                                <span class="audit__badge audit__badge--returned">{{ __('audit.returned') }}</span>
                            @elseif ($box->status)
                                <span class="audit__badge audit__badge--{{ $box->status->value }}">{{ $box->status->label() }}</span>
                            @else
                                <span class="audit__badge audit__badge--todo">{{ __('audit.todo') }}</span>
                            @endif
                        </span>
                        @if ($row['details'] !== '')<span class="audit__muted">{{ $row['details'] }}</span>@endif
                        <span class="audit__box-progress">
                            <span class="audit__bar" role="progressbar" aria-valuenow="{{ $row['percent'] }}" aria-valuemin="0" aria-valuemax="100"><span class="audit__bar-fill" data-vars="width:{{ $row['percent'] }}%"></span></span>
                            <span class="audit__pct">{{ $row['percent'] }}%</span>
                        </span>
                        @if ($row['presences']->isNotEmpty())
                            <span class="audit__whos">
                                @foreach ($row['presences'] as $presence)
                                    <span class="audit__who audit__who--{{ $presence->state->value }}">{{ $presence->state === \App\Enums\AuditPresenceState::OnBox ? '📍' : '🚶' }} {{ $presence->user?->name }}</span>
                                @endforeach
                            </span>
                        @endif
                    </a>
                @endforeach
            @empty
                <div class="empty-state">{{ __('audit.no_box') }}</div>
            @endforelse
        </div>

        @unless ($locked)
            <button type="button" class="btn btn--ghost audit__add-button" wire:click="openWindow('box')">＋ {{ __('audit.add_box') }}</button>
        @endunless

        <div class="audit__foot">
            @if (! $locked && ! $audit->isFinished() && ! $audit->isValidated())
                <button type="button" class="btn btn--primary" wire:click="askFinish({{ $isManager ? 'true' : 'false' }})">🏁 {{ $isManager ? __('audit.finish_validate') : __('audit.finish') }}</button>
            @elseif (! $locked && $isManager && ! $audit->isValidated())
                <button type="button" class="btn btn--primary" wire:click="validateAudit" wire:confirm="{{ __('audit.confirm_validate') }}">✅ {{ __('audit.validate') }}</button>
            @endif
        </div>

        @if ($window === 'point')
            <div class="overlay" wire:click.self="closeWindow" wire:keydown.escape="closeWindow">
                <form class="modal" role="dialog" aria-modal="true" wire:submit="addPoint">
                    <div class="field">
                        <label class="field__label" for="new-point">{{ __('audit.new_point_label') }}</label>
                        <input class="field__input" id="new-point" wire:model="newPoint" maxlength="70" autocomplete="off" autofocus>
                        @error('newPoint') <p class="field__error">{{ $message }}</p> @enderror
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn btn--ghost" wire:click="closeWindow">{{ __('audit.field.cancel') }}</button>
                        <button type="submit" class="btn btn--primary">{{ __('audit.field.ok') }}</button>
                    </div>
                </form>
            </div>
        @elseif ($window === 'box')
            <div class="overlay" wire:click.self="closeWindow" wire:keydown.escape="closeWindow">
                <form class="modal" role="dialog" aria-modal="true" wire:submit="addBox">
                    <div class="field">
                        <label class="field__label" for="new-box">{{ __('audit.new_box_label') }}</label>
                        <input class="field__input" id="new-box" wire:model="newBox" maxlength="{{ \App\Audit\BoxName::MAX_LENGTH }}" autocomplete="off" autofocus>
                        @error('newBox') <p class="field__error">{{ $message }}</p> @enderror
                    </div>
                    <div class="field">
                        <label class="field__label" for="new-box-kind">{{ __('audit.box_kind') }}</label>
                        <select class="field__input" id="new-box-kind" wire:model="newBoxKind">
                            <option value="">{{ __('audit.kind_auto') }}</option>
                            @foreach ($kinds as $kind)<option value="{{ $kind->value }}">{{ $kind->label() }}</option>@endforeach
                        </select>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn btn--ghost" wire:click="closeWindow">{{ __('audit.field.cancel') }}</button>
                        <button type="submit" class="btn btn--primary">{{ __('audit.field.ok') }}</button>
                    </div>
                </form>
            </div>
        @elseif ($window === 'navigate' && $hasCabinetPosition)
            <div class="overlay" wire:click.self="closeWindow" wire:keydown.escape="closeWindow">
                <div class="modal task-nav-sheet" role="dialog" aria-modal="true" aria-labelledby="audit-nav-title">
                    <h3 id="audit-nav-title" class="task-nav-sheet__title">🧭 {{ __('audit.field.navigate_to', ['name' => $audit->cabinet_key.' · '.__('audit.toolbar.navigate_cabinet')]) }}</h3>
                    <p class="task-nav-sheet__coordinates">{{ number_format((float) $audit->cabinet_latitude, 6, '.', '') }}, {{ number_format((float) $audit->cabinet_longitude, 6, '.', '') }}</p>
                    <div class="task-nav-sheet__links" x-data="navigationChoice">
                        @foreach ($navigationLinks as $key => $url)
                            <a class="btn task-nav-sheet__link" x-bind:class="linkClass('{{ $key }}')" href="{{ $url }}" target="_blank" rel="noopener" x-on:click="remember('{{ $key }}')" wire:click="closeWindow">{{ $key === 'waze' ? '🚗' : '🗺️' }} {{ __('tasks.detail.navigation.'.$key) }}</a>
                        @endforeach
                    </div>
                    <button type="button" class="btn btn--ghost task-nav-sheet__close" wire:click="closeWindow">{{ __('audit.field.close') }}</button>
                </div>
            </div>
        @endif
    @endif

    @if ($archiving)
        <div class="modal" role="dialog" aria-modal="true">
            <form class="modal__panel" wire:submit="archive">
                <p>{{ __('audit.archive.question', ['cabinet' => $audit->cabinet_key]) }}</p>
                <label class="field__label" for="archive-code">{{ __('audit.archive.type', ['cabinet' => $audit->cabinet_key]) }}</label>
                <input class="field__input" id="archive-code" wire:model="archiveCode" autocomplete="off" autofocus>
                @error('archiveCode') <p class="field__error">{{ $message }}</p> @enderror
                <div class="form-actions">
                    <button type="button" class="btn btn--ghost" wire:click="cancelArchive">{{ __('audit.field.cancel') }}</button>
                    <button type="submit" class="btn btn--primary btn--danger">{{ __('audit.archive.confirm') }}</button>
                </div>
            </form>
        </div>
    @endif
</div>
