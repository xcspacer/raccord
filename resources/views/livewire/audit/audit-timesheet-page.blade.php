<div class="audit">
    <section class="audit-timesheet">
        <h2 class="audit-timesheet__title">📊 {{ __('audit.timesheet.page_title') }}</h2>

        <div class="audit__filters">
            @foreach (['week', 'lastweek', 'month', 'lastmonth'] as $preset)
                <button type="button" class="audit__filter" wire:click="preset('{{ $preset }}')">{{ __('audit.timesheet.presets.'.$preset) }}</button>
            @endforeach
        </div>

        <div class="field-row">
            <div class="field">
                <label class="field__label" for="timesheet-from">{{ __('audit.timesheet.from') }}</label>
                <input class="field__input" type="date" id="timesheet-from" wire:model.live="from">
            </div>
            <div class="field">
                <label class="field__label" for="timesheet-to">{{ __('audit.timesheet.to') }}</label>
                <input class="field__input" type="date" id="timesheet-to" wire:model.live="to">
            </div>
        </div>

        <p class="audit__muted">{{ __('audit.timesheet.hint') }}</p>

        <a class="btn btn--primary audit-timesheet__generate" href="{{ $downloadUrl }}" @disabled(! $canDownload)>{{ __('audit.timesheet.generate') }}</a>
        <a class="btn btn--ghost audit-timesheet__cancel" href="{{ route('audits.full') }}" wire:navigate>{{ __('audit.field.cancel') }}</a>
    </section>
</div>
