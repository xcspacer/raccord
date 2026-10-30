<section class="settings-card audit__configure">
    <h2 class="audit__section">{{ __('audit.configure.title', ['cabinet' => $cabinet]) }}</h2>

    @error('form') <div class="field-warning" role="alert">{{ $message }}</div> @enderror

    <div class="field-row">
        <div class="field">
            <label class="field__label" for="cfg-mode">{{ __('audit.fields.mode') }}</label>
            <select class="field__input" id="cfg-mode" wire:model="mode" @disabled($modeLocked)>
                @foreach (\App\Enums\AuditMode::cases() as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </select>
            @if ($modeLocked)<p class="field__hint">{{ __('audit.configure.mode_locked') }}</p>@endif
        </div>
        <div class="field">
            <label class="field__label" for="cfg-sro">{{ __('audit.fields.sro') }}</label>
            <input class="field__input" id="cfg-sro" wire:model="sro" maxlength="60">
            @error('sro') <p class="field__error">{{ $message }}</p> @enderror
        </div>
        @if ($workflowChoice)
            <div class="field">
                <label class="field__label" for="cfg-workflow">{{ __('audit.fields.workflow') }}</label>
                <select class="field__input" id="cfg-workflow" wire:model="workflow" @disabled($workflowLocked)>
                    @foreach (\App\Enums\AuditWorkflow::cases() as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </select>
                <p class="field__hint">{{ $workflowLocked ? __('audit.configure.workflow_locked') : __('audit.configure.workflow_hint') }}</p>
            </div>
        @endif
    </div>

    <div class="field">
        <label class="field__label" for="cfg-list">{{ __('audit.configure.list') }}</label>
        <textarea class="field__input" id="cfg-list" rows="6" wire:model.live.debounce.500ms="list" placeholder="{{ __('audit.configure.list_placeholder') }}"></textarea>
        <p class="field__hint">{{ __('audit.configure.list_hint') }}</p>
        @if ($list !== '' || $synopticChosen)
            <p class="audit__muted">{{ $boxCount > 0 ? __('audit.configure.boxes_found', ['count' => $boxCount, 'bpe' => $boxKinds['bpe'], 'pbo' => $boxKinds['pbo'], 'joint' => $boxKinds['jd']]) : __('audit.configure.nothing_found') }}</p>
        @endif
    </div>

    <div class="field">
        <span class="field__label">{{ __('audit.configure.synoptic_file') }}</span>
        <label class="file-picker">
            <input class="file-picker__input" type="file" wire:model="synopticFile" accept=".pdf,.txt,.csv">
            <span class="btn btn--ghost">📄 {{ __('audit.configure.choose') }}</span>
            <span class="file-picker__name">{{ $synopticFile?->getClientOriginalName() ?? $audit?->synoptic_name ?? __('profile.no_file') }}</span>
        </label>
        @error('synopticFile') <p class="field__error">{{ $message }}</p> @enderror
    </div>

    <div class="field">
        <span class="field__label">{{ __('audit.configure.map_file') }}</span>
        <label class="file-picker">
            <input class="file-picker__input" type="file" wire:model="mapFile" accept=".kmz,.kml">
            <span class="btn btn--ghost">🗺 {{ __('audit.configure.choose') }}</span>
            <span class="file-picker__name">{{ $mapFile?->getClientOriginalName() ?? $audit?->kmz_name ?? __('profile.no_file') }}</span>
        </label>
        @error('mapFile') <p class="field__error">{{ $message }}</p> @enderror
        @if ($map)
            <p class="audit__muted">{{ __('audit.configure.map_read', ['located' => $located, 'total' => count($map['boxes'])]) }}@if ($map['cabinet']) · {{ __('audit.configure.cabinet_found') }}@endif</p>
        @endif
    </div>

    <div class="field">
        <span class="field__label">{{ __('audit.configure.drawer_plan') }}</span>
        <label class="file-picker">
            <input class="file-picker__input" type="file" wire:model="drawerPlan" accept=".xlsx,.xls,.pdf">
            <span class="btn btn--ghost">🗄 {{ __('audit.configure.choose') }}</span>
            <span class="file-picker__name">{{ $drawerPlan?->getClientOriginalName() ?? $audit?->plans->firstWhere('kind', \App\Enums\AuditPlanKind::Drawer)?->name ?? __('profile.no_file') }}</span>
        </label>
        @error('drawerPlan') <p class="field__error">{{ $message }}</p> @enderror
    </div>

    <div class="field">
        <span class="field__label">{{ __('audit.configure.box_plans') }}</span>
        <label class="file-picker">
            <input class="file-picker__input" type="file" wire:model="boxPlans" accept=".xlsx,.xls,.pdf" multiple>
            <span class="btn btn--ghost">📐 {{ __('audit.configure.choose') }}</span>
            <span class="file-picker__name">{{ count($boxPlans) > 0 ? trans_choice('audit.configure.files_chosen', count($boxPlans), ['count' => count($boxPlans)]) : trans_choice('audit.configure.plans_kept', $audit?->plans->where('kind', \App\Enums\AuditPlanKind::Box)->count() ?? 0, ['count' => $audit?->plans->where('kind', \App\Enums\AuditPlanKind::Box)->count() ?? 0]) }}</span>
        </label>
        <p class="field__hint">{{ __('audit.configure.plans_hint') }}</p>
        @error('boxPlans.*') <p class="field__error">{{ $message }}</p> @enderror
    </div>

    @if ($summary)
        <div class="audit__banner audit__banner--ok">{{ $summary }}</div>
    @endif
    @if ($toVerify !== [])
        <div class="field-warning" role="status">⚠ {{ __('audit.configure.to_verify', ['names' => implode(', ', array_slice($toVerify, 0, 15)).(count($toVerify) > 15 ? ' … +'.(count($toVerify) - 15) : '')]) }}</div>
    @endif
    @if ($unmatchedPlans !== [])
        <div class="field-warning" role="status">⚠ {{ __('audit.configure.plans_unmatched', ['count' => count($unmatchedPlans), 'names' => implode(', ', array_slice($unmatchedPlans, 0, 15))]) }}</div>
    @endif

    <div class="form-actions form-actions--left">
        <button type="button" class="btn btn--primary" wire:click="save" wire:loading.attr="disabled" wire:target="save,mapFile,boxPlans">{{ __('audit.configure.save') }}</button>
    </div>
</section>
