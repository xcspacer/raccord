<section class="otdr__card">
    <h2 class="otdr__heading">{{ __('otdr.measurement_report.heading') }}</h2>
    <div class="otdr__row otdr__row--bottom">
        <template x-for="field in reportFieldList" x-bind:key="field.key">
            <label class="otdr__field"><span x-text="field.label"></span>
                <input x-bind:value="field.value" x-on:input="setReportField(field.key, $event)">
            </label>
        </template>
    </div>
    <div class="otdr__row otdr__row--wide otdr__options">
        <template x-for="option in reportOptionList" x-bind:key="option.key">
            <label class="otdr__check otdr__check--dim">
                <input type="checkbox" x-bind:checked="option.checked" x-on:change="setReportOption(option.key, $event)"><span x-text="option.label"></span>
            </label>
        </template>
    </div>
    <div class="otdr__row otdr__actions">
        <button type="button" class="otdr__btn otdr__btn--primary" x-bind:disabled="reportBusy" x-on:click="downloadReport" x-text="reportLabel"></button>
        <button type="button" class="otdr__btn" x-on:click="downloadStandardTxt">{{ __('otdr.measurement_report.download_txt') }}</button>
        <button type="button" class="otdr__btn" x-bind:disabled="reportBusy" x-on:click="downloadStandardArchive">{{ __('otdr.measurement_report.download_archive') }}</button>
    </div>
    <p class="otdr__warn-text" x-show="reportError !== ''" x-text="reportError"></p>
    <p class="otdr__muted otdr__note-last" x-text="reportFooter"></p>
</section>
