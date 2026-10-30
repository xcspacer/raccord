<section class="otdr__card">
    <h2 class="otdr__heading">{{ __('otdr.gdco_deliverables.heading') }}</h2>
    <div class="otdr__row otdr__row--bottom">
        <label class="otdr__field">{{ __('otdr.gdco_deliverables.zone') }}
            <input placeholder="{{ __('otdr.gdco_deliverables.zone_placeholder') }}" x-bind:value="gdcoFields.zone" x-on:input="setGdcoField('zone', $event)">
        </label>
        <label class="otdr__field">{{ __('otdr.gdco_deliverables.g2r') }}
            <input inputmode="numeric" x-bind:value="gdcoFields.g2r" x-on:input="setGdcoField('g2r', $event)">
        </label>
        <label class="otdr__field">{{ __('otdr.gdco_deliverables.pm') }}
            <input placeholder="{{ __('otdr.gdco_deliverables.pm_placeholder') }}" x-bind:value="gdcoFields.pm" x-on:input="setGdcoField('pm', $event)">
        </label>
        <label class="otdr__field">{{ __('otdr.gdco_deliverables.stit') }}
            <input x-bind:value="gdcoFields.stit" x-on:input="setGdcoField('stit', $event)">
        </label>
        <label class="otdr__field">{{ __('otdr.gdco_deliverables.technician') }}
            <input x-bind:value="gdcoFields.technician" x-on:input="setGdcoField('technician', $event)">
        </label>
        <template x-if="isGdcoTransport">
            <label class="otdr__field">{{ __('otdr.gdco_deliverables.transport_type') }}
                <select class="otdr__select otdr__select--field" x-on:change="setGdcoField('transportType', $event)">
                    <option value="FTTH" x-bind:selected="gdcoFields.transportType === 'FTTH'">{{ __('otdr.gdco_deliverables.ftth') }}</option>
                    <option value="FTTE" x-bind:selected="gdcoFields.transportType === 'FTTE'">{{ __('otdr.gdco_deliverables.ftte') }}</option>
                </select>
            </label>
        </template>
        <template x-if="isGdcoTransport">
            <label class="otdr__field">{{ __('otdr.gdco_deliverables.total_fibers') }}
                <input inputmode="numeric" placeholder="{{ __('otdr.gdco_deliverables.total_fibers_placeholder') }}" x-bind:value="gdcoFields.totalFibers" x-on:input="setGdcoField('totalFibers', $event)">
            </label>
        </template>
        <template x-if="isGdcoDistribution">
            <div class="otdr__field">
                <span>{{ __('otdr.gdco_deliverables.rop') }}</span>
                <label class="file-picker otdr__file">
                    <input class="file-picker__input" type="file" accept=".csv,text/csv" x-on:change="onRop($event)">
                    <span class="otdr__file-button">{{ __('otdr.gdco_deliverables.choose_file') }}</span>
                    <span class="otdr__file-name" x-text="ropName"></span>
                </label>
            </div>
        </template>
    </div>
    <p class="otdr__muted" x-show="ropText !== ''" x-text="ropText"></p>
    <template x-if="showPreRepair">
        <div class="otdr__row otdr__pre-repair">
            <div class="otdr__field">
                <span>{{ __('otdr.gdco_deliverables.pre_repair') }}</span>
                <label class="file-picker otdr__file">
                    <input class="file-picker__input" type="file" multiple accept=".sor" x-on:change="onPreRepair($event)">
                    <span class="otdr__file-button">{{ __('otdr.gdco_deliverables.choose_files') }}</span>
                </label>
            </div>
            <span class="otdr__muted" x-text="preRepairText"></span>
        </div>
    </template>
    <div class="otdr__row otdr__actions">
        <button type="button" class="otdr__btn" x-on:click="downloadGdcoTxt">{{ __('otdr.gdco_deliverables.download_txt') }}</button>
        <button type="button" class="otdr__btn" x-bind:disabled="gdcoBlocked" x-on:click="downloadDco" x-text="dcoLabel"></button>
        <button type="button" class="otdr__btn otdr__btn--primary" x-bind:disabled="gdcoBlocked" x-on:click="downloadGdcoArchive">{{ __('otdr.gdco_deliverables.download_archive') }}</button>
    </div>
    <p class="otdr__warn-text" x-show="gdcoRedLight">{{ __('otdr.gdco_deliverables.red_light') }}</p>
    <p class="otdr__muted" x-show="isGdcoDistribution && ropSummary === null">{{ __('otdr.gdco_deliverables.no_rop') }}</p>
    <p class="otdr__muted" x-show="gdcoMissing !== ''" x-text="gdcoMissing"></p>
    <p class="otdr__warn-text" x-show="gdcoError !== ''" x-text="gdcoError"></p>
    <p class="otdr__muted otdr__note-last" x-text="gdcoFooter"></p>
</section>
