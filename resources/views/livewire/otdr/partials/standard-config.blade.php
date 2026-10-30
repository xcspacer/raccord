<div class="otdr__schema">
    <svg class="otdr__schema-svg" role="img" x-effect="drawSchema($el)"></svg>
</div>
<label class="otdr__check otdr__check--auto">
    <input type="checkbox" x-bind:checked="acquisition.auto" x-on:change="setAuto($event)">
    <span>{{ __('otdr.config.auto') }} <span class="otdr__muted" x-show="appliedSummary !== ''" x-text="appliedSummary"></span></span>
</label>

<div class="otdr__seg otdr__seg--tabs" role="tablist">
    <button type="button" x-bind:aria-pressed="configTab === 'otdr'" x-on:click="setConfigTab('otdr')">{{ __('otdr.config.tab_otdr') }}</button>
    <button type="button" x-bind:aria-pressed="configTab === 'detection'" x-on:click="setConfigTab('detection')">{{ __('otdr.config.tab_detection') }}</button>
    <button type="button" x-bind:aria-pressed="configTab === 'thresholds'" x-on:click="setConfigTab('thresholds')">{{ __('otdr.config.tab_thresholds') }}</button>
    <button type="button" x-bind:aria-pressed="configTab === 'misc'" x-on:click="setConfigTab('misc')">{{ __('otdr.config.tab_misc') }}</button>
</div>

<template x-if="configTab === 'otdr'">
    <div>
        <fieldset class="otdr__group">
            <legend>{{ __('otdr.config.start_spools') }}</legend>
            <div class="otdr__row">
                <template x-for="choice in spoolChoices('startSpools')" x-bind:key="choice.key">
                    <button type="button" x-bind:class="choice.className" x-bind:aria-pressed="choice.pressed" x-on:click="choose('startSpools', choice.value)">
                        <svg aria-hidden="true" x-effect="paintIcon($el, choice.icon, choice.value)"></svg>
                        <span x-text="choice.label"></span>
                    </button>
                </template>
            </div>
            <p class="otdr__muted otdr__hint">{{ __('otdr.config.start_spools_hint') }}</p>
        </fieldset>
        <fieldset class="otdr__group">
            <legend>{{ __('otdr.config.end_spools') }}</legend>
            <div class="otdr__row">
                <template x-for="choice in spoolChoices('endSpools')" x-bind:key="choice.key">
                    <button type="button" x-bind:class="choice.className" x-bind:aria-pressed="choice.pressed" x-on:click="choose('endSpools', choice.value)">
                        <svg aria-hidden="true" x-effect="paintIcon($el, choice.icon, choice.value)"></svg>
                        <span x-text="choice.label"></span>
                    </button>
                </template>
            </div>
            <p class="otdr__muted otdr__hint">{{ __('otdr.config.end_spools_hint') }}</p>
        </fieldset>
        <fieldset class="otdr__group">
            <legend>{{ __('otdr.config.measure_type') }}</legend>
            <div class="otdr__row">
                <template x-for="choice in measureChoices" x-bind:key="choice.key">
                    <button type="button" x-bind:class="choice.className" x-bind:aria-pressed="choice.pressed" x-on:click="choose('loop', choice.value)">
                        <svg aria-hidden="true" x-effect="paintIcon($el, 'measure', choice.value)"></svg>
                        <span x-text="choice.label"></span>
                    </button>
                </template>
            </div>
        </fieldset>

        <template x-if="isLoop">
            <div>
                <fieldset class="otdr__group">
                    <legend>{{ __('otdr.config.loop_spools') }}</legend>
                    <div class="otdr__row">
                        <template x-for="choice in spoolChoices('loopSpools')" x-bind:key="choice.key">
                            <button type="button" x-bind:class="choice.className" x-bind:aria-pressed="choice.pressed" x-on:click="choose('loopSpools', choice.value)">
                                <svg aria-hidden="true" x-effect="paintIcon($el, choice.icon, choice.value)"></svg>
                                <span x-text="choice.label"></span>
                            </button>
                        </template>
                    </div>
                    <p class="otdr__muted otdr__hint">{{ __('otdr.config.loop_spools_hint') }}</p>
                </fieldset>
                <fieldset class="otdr__group">
                    <legend>{{ __('otdr.config.direction') }}</legend>
                    <div class="otdr__row">
                        <template x-for="choice in directionChoices" x-bind:key="choice.key">
                            <button type="button" x-bind:class="choice.className" x-bind:aria-pressed="choice.pressed" x-on:click="choose('loopDirection', choice.value)">
                                <svg aria-hidden="true" x-effect="paintIcon($el, 'direction', choice.value)"></svg>
                                <span x-text="choice.label"></span>
                            </button>
                        </template>
                    </div>
                </fieldset>
                <fieldset class="otdr__group">
                    <legend>{{ __('otdr.config.sequence') }}</legend>
                    <div class="otdr__row">
                        <template x-for="choice in sequenceChoices" x-bind:key="choice.key">
                            <button type="button" x-bind:class="choice.className" x-bind:aria-pressed="choice.pressed" x-on:click="choose('sequence', choice.value)">
                                <span x-text="choice.label"></span>
                            </button>
                        </template>
                    </div>
                    <p class="otdr__muted otdr__hint" x-text="sequenceHint"></p>
                </fieldset>
            </div>
        </template>
        <p class="otdr__muted otdr__default-note">{{ __('otdr.config.default_note') }}</p>
    </div>
</template>

<template x-if="configTab === 'detection'">
    <div>
        <label class="otdr__check otdr__check--top">
            <input type="checkbox" x-bind:checked="acquisition.completeAnalysis" x-on:change="setCompleteAnalysis($event)">
            <span>
                <b>{{ __('otdr.config.complete_analysis') }}</b> {{ __('otdr.config.recommended') }}
                <span class="otdr__muted otdr__block">{{ __('otdr.config.complete_analysis_help') }}</span>
            </span>
        </label>
        <fieldset class="otdr__group otdr__group--detection" x-bind:disabled="!acquisition.completeAnalysis">
            <legend>{{ __('otdr.config.detection') }}</legend>
            <table class="otdr__table otdr__thresholds otdr__thresholds--narrow">
                <tbody>
                    <template x-for="row in detectionRows" x-bind:key="row.key">
                        <tr x-bind:class="acquisition.completeAnalysis ? '' : 'is-inactive'">
                            <td class="otdr__threshold-label"><span x-text="row.label"></span><span class="otdr__muted otdr__block otdr__threshold-hint" x-text="row.hint"></span></td>
                            <td><input class="otdr__number otdr__number--wide" inputmode="decimal" x-bind:value="row.value" x-on:input="setDetection(row.key, $event)"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
            <button type="button" class="otdr__link otdr__reset" x-show="!detectionIsDefault" x-on:click="resetDetection" x-text="detectionResetLabel"></button>
        </fieldset>
    </div>
</template>

<template x-if="configTab === 'thresholds'">
    <div>
        <label class="otdr__field otdr__field--profile">{{ __('otdr.config.profile') }}
            <select class="otdr__select otdr__select--profile" x-on:change="selectProfile($event)">
                <template x-for="option in profileOptions" x-bind:key="option.value">
                    <option x-bind:value="option.value" x-bind:selected="option.selected" x-text="option.label"></option>
                </template>
            </select>
        </label>
        <div class="otdr__table-wrap otdr__table-wrap--narrow">
            <table class="otdr__table otdr__thresholds">
                <thead>
                    <tr>
                        <th>{{ __('otdr.config.column_active') }}</th>
                        <th class="otdr__left">{{ __('otdr.config.column_threshold') }}</th>
                        <th>{{ __('otdr.config.column_1310') }}</th>
                        <th>{{ __('otdr.config.column_1550') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="row in thresholdRows" x-bind:key="row.key">
                        <tr x-bind:class="row.className">
                            <template x-if="row.group">
                                <td class="otdr__threshold-group" colspan="4" x-text="row.label"></td>
                            </template>
                            <template x-if="!row.group">
                                <td><input type="checkbox" x-bind:checked="row.active" x-bind:aria-label="row.aria" x-on:change="setThresholdActive(row.key, $event)"></td>
                            </template>
                            <template x-if="!row.group">
                                <td class="otdr__threshold-label" x-text="row.label"></td>
                            </template>
                            <template x-if="!row.group">
                                <td><input class="otdr__number" inputmode="decimal" x-bind:disabled="!row.active" x-bind:value="row.v1310" x-on:input="setThreshold(1310, row.key, $event)"></td>
                            </template>
                            <template x-if="!row.group">
                                <td><input class="otdr__number" inputmode="decimal" x-bind:disabled="!row.active" x-bind:value="row.v1550" x-on:input="setThreshold(1550, row.key, $event)"></td>
                            </template>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <div class="otdr__row otdr__row--wide otdr__options">
            <label class="otdr__check">
                <input type="checkbox" x-bind:checked="profile.negativeAlarm" x-on:change="setNegativeAlarm($event)"> {{ __('otdr.config.negative_alarm') }}
            </label>
            <label class="otdr__check">
                <input type="checkbox" x-bind:checked="constraintEnabled" x-on:change="setConstraintEnabled($event)"> {{ __('otdr.config.constraints') }}
                <input class="otdr__number" inputmode="decimal" x-bind:disabled="!constraintEnabled" x-bind:value="constraintValue" x-on:input="setConstraint($event)">
            </label>
        </div>
        <p class="otdr__muted otdr__note-last">{{ __('otdr.config.thresholds_note') }}</p>
    </div>
</template>

<template x-if="configTab === 'misc'">
    <fieldset class="otdr__group">
        <legend>{{ __('otdr.config.total_loss') }}</legend>
        <div class="otdr__row">
            <label class="otdr__check otdr__check--wide">
                <input type="checkbox" x-bind:checked="shownAcquisition.totalLossInput" x-on:change="setTotalLoss('totalLossInput', $event)"> {{ __('otdr.config.total_loss_input') }}
            </label>
            <label class="otdr__check otdr__check--wide">
                <input type="checkbox" x-bind:checked="shownAcquisition.totalLossOutput" x-on:change="setTotalLoss('totalLossOutput', $event)"> {{ __('otdr.config.total_loss_output') }}
            </label>
        </div>
    </fieldset>
</template>
