<section class="otdr__card">
    <h2 class="otdr__heading">
        <span x-show="isStandard">{{ __('otdr.setup.heading_standard') }}</span>
        <span x-show="isGdco" x-cloak>{{ __('otdr.setup.heading_gdco') }}</span>
    </h2>

    <template x-if="isStandard">
        <div>
            @include('livewire.otdr.partials.standard-config')
        </div>
    </template>

    <template x-if="isGdco">
        <div class="otdr__row">
            <div class="otdr__seg" role="group" aria-label="{{ __('otdr.setup.kind_label') }}">
                <button type="button" x-bind:aria-pressed="kindChoice === 'auto'" x-on:click="setKind('auto')">{{ __('otdr.setup.kind_auto') }}</button>
                <button type="button" x-bind:aria-pressed="kindChoice === 'transport'" x-on:click="setKind('transport')">{{ __('otdr.messages.dossier_kind_transport') }}</button>
                <button type="button" x-bind:aria-pressed="kindChoice === 'distribution'" x-on:click="setKind('distribution')">{{ __('otdr.messages.dossier_kind_distribution') }}</button>
            </div>
        </div>
    </template>

    <template x-if="showNroPm">
        @include('livewire.otdr.partials.nro-pm')
    </template>

    <template x-if="hasFibers">
        <div class="otdr__row otdr__row--wide otdr__corrections">
            <span class="otdr__muted" x-text="correctionsCount"></span>
            <button type="button" class="otdr__link" x-bind:disabled="cannotExportCorrections" x-on:click="exportCorrections">{{ __('otdr.corrections.export') }}</button>
            <button type="button" class="otdr__link" x-on:click="chooseCorrections">{{ __('otdr.corrections.import') }}</button>
            <button type="button" class="otdr__link" x-show="overrides.length > 0" x-on:click="undoAllCorrections">{{ __('otdr.corrections.undo_all') }}</button>
            <input type="file" accept=".json,application/json" hidden x-ref="corrections" x-on:change="importCorrections($event)">
        </div>
    </template>
    <p class="otdr__muted otdr__note-last" x-show="correctionsNotice !== ''" x-text="correctionsNotice"></p>
</section>
