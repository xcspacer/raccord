{{-- Alpine runs in its CSP-safe build: expressions stay simple (properties, calls); the logic lives in resources/js/otdr/ui. --}}
<div class="otdr" wire:ignore x-data="otdrLoader" data-texts="{{ json_encode(__('otdr'), JSON_UNESCAPED_UNICODE) }}" data-locale="{{ app()->getLocale() }}" data-product="{{ $product }}">
    <p class="otdr__state" x-show="loading">{{ __('otdr.loading') }}</p>
    <p class="otdr__state" x-show="failed" x-cloak>{{ __('otdr.unavailable') }}</p>

    <template x-if="ready">
        <div class="otdr__screen" x-data="otdrAcceptance">
            <h1 class="otdr__title">{{ __('otdr.title') }}</h1>
            <p class="otdr__subtitle">{{ __('otdr.subtitle') }}</p>
            <div class="otdr__seg otdr__seg--treatment" role="group" aria-label="{{ __('otdr.treatment.label') }}">
                <button type="button" x-bind:aria-pressed="isStandard" x-on:click="setTreatment('standard')">{{ __('otdr.treatment.standard') }}</button>
                <button type="button" x-bind:aria-pressed="isGdco" x-on:click="setTreatment('gdco')">{{ __('otdr.treatment.gdco') }}</button>
            </div>

            @include('livewire.otdr.partials.files')
            @include('livewire.otdr.partials.setup')

            <template x-if="showRepairs">
                @include('livewire.otdr.partials.failed-repairs')
            </template>

            <div x-ref="trace">
                <template x-if="traceVisible">
                    @include('livewire.otdr.partials.trace')
                </template>
            </div>

            @include('livewire.otdr.partials.results')

            <template x-if="showGdcoDeliverables">
                @include('livewire.otdr.partials.gdco-deliverables')
            </template>

            <template x-if="showStandardDeliverables">
                <div>
                    @include('livewire.otdr.partials.measurement-report')

                    <template x-if="showGdcoExport">
                        <details class="otdr__card" x-bind:open="gdcoExportOpen" x-on:toggle="toggleGdcoExport($event)">
                            <summary class="otdr__export-summary" x-text="views.gdcoExportSummary"></summary>
                            <p class="otdr__muted" x-text="gdcoExportNote"></p>
                            <template x-if="isDistribution">
                                @include('livewire.otdr.partials.nro-pm')
                            </template>
                            @include('livewire.otdr.partials.gdco-deliverables')
                        </details>
                    </template>
                </div>
            </template>
        </div>
    </template>
</div>
