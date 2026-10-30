<section class="otdr__card" aria-label="{{ __('otdr.trace.aria') }}">
    <div class="otdr__row otdr__row--between">
        <h2 class="otdr__heading otdr__heading--inline" x-text="traceHeading"></h2>
        <button type="button" class="otdr__btn" x-on:click="closeTrace">{{ __('otdr.trace.close') }}</button>
    </div>
    <div class="otdr__seg otdr__trace-tabs" role="tablist" x-show="traceTabs.length > 0">
        <template x-for="tab in traceTabs" x-bind:key="tab.key">
            <button type="button" x-bind:aria-pressed="tab.pressed" x-on:click="selectTraceTab(tab.index)" x-text="tab.label"></button>
        </template>
    </div>
    <p class="otdr__muted otdr__trace-file" x-text="traceFileDetails"></p>
    <div x-bind:class="plotBoxClass" x-init="observePlot($el)">
        <svg class="otdr__plot-svg" role="img" aria-label="{{ __('otdr.trace.plot') }}" x-effect="drawTracePlot($el)" x-on:click="plotClick($event)"></svg>
    </div>
    <div class="otdr__row otdr__trace-tools">
        <button type="button" class="otdr__btn" aria-label="{{ __('otdr.trace.pan_left') }}" x-on:click="panTrace(-0.5)">◀</button>
        <button type="button" class="otdr__btn" x-on:click="zoomTrace(0.5)">{{ __('otdr.trace.zoom_in') }}</button>
        <button type="button" class="otdr__btn" x-on:click="zoomTrace(2)">{{ __('otdr.trace.zoom_out') }}</button>
        <button type="button" class="otdr__btn" x-on:click="showWholeTrace">{{ __('otdr.trace.all') }}</button>
        <button type="button" class="otdr__btn" aria-label="{{ __('otdr.trace.pan_right') }}" x-on:click="panTrace(0.5)">▶</button>
    </div>
    <div class="otdr__row otdr__row--bottom otdr__trace-tools">
        <button type="button" x-bind:class="addingClass" x-on:click="toggleAdding" x-text="addingLabel"></button>
        <label class="otdr__field">{{ __('otdr.trace.add_at') }}
            <input class="otdr__input--short" inputmode="decimal" placeholder="{{ __('otdr.trace.add_at_placeholder') }}" x-bind:value="trace.addAt" x-on:input="setAddAt($event)">
        </label>
        <button type="button" class="otdr__btn" x-bind:disabled="cannotAddAt" x-on:click="addAtDistance">{{ __('otdr.trace.add_button') }}</button>
    </div>
    <p class="otdr__warn-text" x-show="trace.error !== ''" x-text="trace.error"></p>

    <div class="otdr__table-wrap otdr__trace-table">
        <table class="otdr__table">
            <thead>
                <tr>
                    <th>{{ __('otdr.trace.column_number') }}</th>
                    <th>{{ __('otdr.trace.column_trace') }}</th>
                    <th>{{ __('otdr.trace.column_from_first') }}</th>
                    <th>{{ __('otdr.trace.column_loss') }}</th>
                    <th>{{ __('otdr.trace.column_reflectance') }}</th>
                    <th>{{ __('otdr.trace.column_slope') }}</th>
                    <th>{{ __('otdr.trace.column_type') }}</th>
                    <th>{{ __('otdr.trace.column_action') }}</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="row in traceRows" x-bind:key="row.key">
                    <tr x-bind:class="row.className">
                        <td><button type="button" class="otdr__link" x-on:click="focusTraceRow(row.index)" x-text="row.label"></button></td>
                        <td x-text="row.trace"></td>
                        <td x-text="row.fromFirst"></td>
                        <td x-text="row.loss"></td>
                        <td x-text="row.reflectance"></td>
                        <td x-bind:title="row.slopeTitle"><span x-text="row.slope"></span><span class="otdr__muted otdr__slope-tag" x-show="row.slopeTag !== ''" x-text="row.slopeTag"></span></td>
                        <td class="otdr__cell-text">
                            <template x-if="row.selectable">
                                <select class="otdr__select otdr__select--small" x-bind:disabled="row.className === 'is-ignored'" x-on:change="retypeTraceRow(row.index, $event)">
                                    <option value="connector" x-bind:selected="row.kind === 'connector'">{{ __('otdr.trace.connector') }}</option>
                                    <option value="splice" x-bind:selected="row.kind === 'splice'">{{ __('otdr.trace.splice') }}</option>
                                </select>
                            </template>
                            <template x-if="!row.selectable">
                                <span x-text="row.typeText"></span>
                            </template>
                        </td>
                        <td class="otdr__cell-text">
                            <button type="button" class="otdr__link" x-show="row.action !== ''" x-on:click="traceRowAction(row.index)" x-text="row.actionLabel"></button>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
    <p class="otdr__muted otdr__note-last">
        {{ __('otdr.trace.legend') }} <span class="otdr__detected">{{ __('otdr.trace.legend_detected') }}</span> {{ __('otdr.trace.legend_rest') }} <span x-text="traceLegendTail"></span>
    </p>
</section>
