@php($technicianLabel = fn ($technician) => $technician->name.($technician->vehicle?->model ? ' — '.$technician->vehicle->model.($technician->vehicle->plate ? ' - '.$technician->vehicle->plate : '') : ''))

<div class="field">
    <label class="field__label" for="ws-pm">{{ __('work_sites.fields.pm') }} *</label>
    <input class="field__input" id="ws-pm" list="ws-pms" wire:model.live.debounce.400ms="daily.cabinetRef" placeholder="{{ __('work_sites.pm_placeholder') }}" autocomplete="off" autocapitalize="characters">
    <datalist id="ws-pms">
        @foreach ($plannedPms as $pm)
            <option value="{{ $pm }}"></option>
        @endforeach
    </datalist>
    @error('daily.cabinetRef') <p class="field__error">{{ $message }}</p> @enderror
</div>

<div class="field-row">
    <div class="field">
        <label class="field__label" for="ws-date">{{ $editingId ? __('work_sites.fields.date') : __('work_sites.fields.starts_on') }} *</label>
        <input class="field__input" type="date" id="ws-date" wire:model="daily.date">
        @error('daily.date') <p class="field__error">{{ $message }}</p> @enderror
    </div>
    <div class="field">
        <label class="field__label" for="ws-end-date">{{ $editingId ? __('work_sites.fields.end_date_optional') : __('work_sites.fields.ends_on').' *' }}</label>
        <input class="field__input" type="date" id="ws-end-date" wire:model="daily.endDate">
        @error('daily.endDate') <p class="field__error">{{ $message }}</p> @enderror
    </div>
</div>
@error('date') <p class="field__error">{{ $message }}</p> @enderror

@if ($canConfigureAudit && (! $editingId || $pmAudit))
    <div class="field">
        @unless ($editingId)
            <span class="field__label">{{ __('work_sites.audit_files.in_progress') }}</span>
            <div class="pm-chips">
                @forelse ($pmsInProgress as $chip)
                    <button type="button" @class(['pm-chip', 'is-active' => $chip['key'] === $pmKey]) wire:click="pickPm('{{ $chip['key'] }}')" wire:key="pm-chip-{{ $chip['key'] }}">{{ $chip['reference'] }}@if ($chip['boxes']) <span class="pm-chip__count">{{ $chip['done'] }}/{{ $chip['boxes'] }}</span>@endif</button>
                @empty
                    <span class="pm-hint">{{ __('work_sites.audit_files.none_in_progress') }}</span>
                @endforelse
            </div>
        @endunless
        @if ($pmAudit)
            <p class="pm-hint">✅ <strong>{{ __('work_sites.audit_files.in_progress_short') }}</strong>@if ($pmAudit['sro']) · {{ $pmAudit['sro'] }}@endif · {{ __('work_sites.audit_files.progress', ['boxes' => $pmAudit['boxes'], 'done' => $pmAudit['done']]) }}
                @if ($pmAudit['configured'])
                    {{ __('work_sites.audit_files.already_saved') }} <strong>{{ __('work_sites.audit_files.nothing_to_send') }}</strong>.
                    <button type="button" class="pm-hint__toggle" wire:click="toggleAuditFiles">{{ $showAuditFiles ? __('work_sites.audit_files.hide_files') : __('work_sites.audit_files.edit_files') }}</button>
                @endif
            </p>
        @endif
    </div>
@endif

@if ($canConfigureAudit && $auditFilesShown)
    <div class="field">
        <label class="field__label" for="ws-audit-sro">{{ __('work_sites.audit_files.sro') }}</label>
        <input class="field__input" id="ws-audit-sro" wire:model="auditSro" maxlength="60" placeholder="{{ __('work_sites.audit_files.sro_placeholder') }}" autocomplete="off">
        @error('auditSro') <p class="field__error">{{ $message }}</p> @enderror
    </div>
@endif

@foreach ($daily->teams as $index => $team)
    <div class="box-row" wire:key="team-{{ $index }}">
        <div class="field">
            <label class="field__label" for="ws-team-lead-{{ $index }}">{{ __('work_sites.team_lead', ['n' => $index + 1]) }} @if ($index === 0)*@endif</label>
            <select class="field__input" id="ws-team-lead-{{ $index }}" wire:model="daily.teams.{{ $index }}.lead">
                <option value="">—</option>
                @foreach ($technicians as $technician)
                    <option value="{{ $technician->id }}">{{ $technicianLabel($technician) }}</option>
                @endforeach
            </select>
            @error('daily.teams.'.$index.'.lead') <p class="field__error">{{ $message }}</p> @enderror
        </div>
        <div class="field">
            <label class="field__label" for="ws-team-partner-{{ $index }}">{{ __('work_sites.team_partner', ['n' => $index + 1]) }}</label>
            <select class="field__input" id="ws-team-partner-{{ $index }}" wire:model="daily.teams.{{ $index }}.partner">
                <option value="">—</option>
                @foreach ($technicians as $technician)
                    <option value="{{ $technician->id }}">{{ $technicianLabel($technician) }}</option>
                @endforeach
            </select>
        </div>
        @if (! $editingId && count($daily->teams) > 1)
            <div class="field">
                <button type="button" class="btn btn--ghost btn--danger" wire:click="removeTeam({{ $index }})">✕ {{ __('work_sites.remove_team', ['n' => $index + 1]) }}</button>
            </div>
        @endif
    </div>
@endforeach
@error('teams') <p class="field__error">{{ $message }}</p> @enderror

@if (! $editingId)
    <div class="field">
        <button type="button" class="btn btn--ghost" wire:click="addTeam">+ {{ __('work_sites.add_team') }}</button>
    </div>
@endif

<div class="field">
    <label class="field__label" for="ws-notes">{{ __('work_sites.fields.notes') }}</label>
    <textarea class="field__input" id="ws-notes" rows="3" wire:model="daily.notes"></textarea>
</div>

@if ($canConfigureAudit)
    <div class="field">
        <span class="field__label">{{ __('work_sites.audit_files.map') }}</span>
        <label class="file-picker">
            <input class="file-picker__input" type="file" wire:model="kmzFile" accept=".kmz,.kml">
            <span class="btn btn--ghost">{{ __('profile.choose_file') }}</span>
            <span class="file-picker__name">{{ $kmzFile?->getClientOriginalName() ?? __('profile.no_file') }}</span>
        </label>
        @if ($mapSummary) <p class="pm-hint">{{ $mapSummary }}</p> @endif
        @error('kmzFile') <p class="field__error">{{ $message }}</p> @enderror
    </div>

    @if ($auditFilesShown)
        <div class="field">
            <span class="field__label">{{ __('work_sites.fields.synoptic') }}</span>
            <label class="file-picker">
                <input class="file-picker__input" type="file" wire:model="synopticFile" accept=".pdf,.txt,.csv">
                <span class="btn btn--ghost">{{ __('profile.choose_file') }}</span>
                <span class="file-picker__name">{{ $synopticFile?->getClientOriginalName() ?? __('profile.no_file') }}</span>
            </label>
            @if ($synopticSummary) <p class="pm-hint">{{ $synopticSummary }}</p> @endif
            @error('synopticFile') <p class="field__error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <span class="field__label">{{ __('work_sites.audit_files.drawer_plan') }}</span>
            <label class="file-picker">
                <input class="file-picker__input" type="file" wire:model="drawerPlan" accept=".xlsx,.xls,.pdf">
                <span class="btn btn--ghost">{{ __('profile.choose_file') }}</span>
                <span class="file-picker__name">{{ $drawerPlan?->getClientOriginalName() ?? __('profile.no_file') }}</span>
            </label>
            @error('drawerPlan') <p class="field__error">{{ $message }}</p> @enderror
            <span class="field__label pm-files__second">{{ __('work_sites.audit_files.box_plans') }}</span>
            <label class="file-picker">
                <input class="file-picker__input" type="file" wire:model="boxPlans" accept=".xlsx,.xls,.pdf" multiple>
                <span class="btn btn--ghost">{{ __('profile.choose_file') }}</span>
                <span class="file-picker__name">{{ $boxPlans === [] ? __('profile.no_file') : trans_choice('audit.configure.files_chosen', count($boxPlans), ['count' => count($boxPlans)]) }}</span>
            </label>
            @error('boxPlans') <p class="field__error">{{ $message }}</p> @enderror
            @error('boxPlans.*') <p class="field__error">{{ $message }}</p> @enderror
        </div>
    @endif
@endif
