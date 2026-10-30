{{-- Alpine runs in its CSP-safe build: expressions stay simple (properties, calls), the logic lives in resources/js/fibre-code/converter.js. --}}
<div @class(['fibre-code', 'fibre-code--standalone' => $standalone]) x-data="fibreCode" data-texts="{{ json_encode(__('fibre_code'), JSON_UNESCAPED_UNICODE) }}" x-cloak>
    <header class="fibre-code__head">
        <div>
            @if ($standalone)
                <h1 class="fibre-code__title">{{ __('fibre_code.heading') }} <span x-text="codeName"></span></h1>
                <p class="fibre-code__subtitle">{{ __('fibre_code.subtitle') }}</p>
            @else
                <h2 class="fibre-code__title">{{ __('fibre_code.heading') }} <span x-text="codeName"></span></h2>
            @endif
        </div>
        <div class="fibre-code__capacity"><b x-text="capacity"></b> {{ __('fibre_code.fibres_unit') }} · <b x-text="tubeCount"></b> {{ __('fibre_code.tubes_unit') }} × <b x-text="fibresPerTube"></b> {{ __('fibre_code.fibres_unit') }}</div>
    </header>

    <section class="fibre-code__block">
        <h2 class="fibre-code__label">{{ __('fibre_code.cable') }}</h2>
        <div class="fibre-code__settings">
            <label class="fibre-code__field">{{ __('fibre_code.code') }}
                <select class="fibre-code__input" x-on:change="setCode($event)">
                    <template x-for="option in codeOptions" x-bind:key="option.value">
                        <option x-bind:value="option.value" x-bind:selected="option.selected" x-text="option.label"></option>
                    </template>
                </select>
            </label>
            <label class="fibre-code__field">{{ __('fibre_code.fibres_per_tube') }}
                <select class="fibre-code__input" x-on:change="setFibresPerTube($event)">
                    <template x-for="option in fibresPerTubeOptions" x-bind:key="option.value">
                        <option x-bind:value="option.value" x-bind:selected="option.selected" x-text="option.value"></option>
                    </template>
                </select>
            </label>
            <label class="fibre-code__field">{{ __('fibre_code.capacity') }}
                <select class="fibre-code__input" x-on:change="setCapacity($event)">
                    <template x-for="option in capacityOptions" x-bind:key="option.value">
                        <option x-bind:value="option.value" x-bind:selected="option.selected" x-text="option.value"></option>
                    </template>
                </select>
            </label>
            <label class="fibre-code__field">{{ __('fibre_code.first_fibre') }}
                <input class="fibre-code__input fibre-code__input--short" type="number" min="0" x-bind:value="start" x-on:change="setStart($event)">
            </label>
        </div>
        <p class="fibre-code__note" x-text="codeNote"></p>

        <div x-show="isCustom">
            <h3 class="fibre-code__label fibre-code__label--custom">{{ $standalone ? __('fibre_code.custom_order_browser') : __('fibre_code.custom_order_device') }}</h3>
            <div class="fibre-code__custom">
                <template x-for="slot in customSlots" x-bind:key="slot.index">
                    <label class="fibre-code__custom-slot">
                        <span class="fibre-code__position" x-text="slot.position"></span>
                        <span x-bind:class="slot.swatchClass"></span>
                        <select class="fibre-code__input" x-on:change="setCustomColour(slot.index, $event)">
                            <template x-for="option in slot.options" x-bind:key="option.value">
                                <option x-bind:value="option.value" x-bind:selected="option.selected" x-text="option.label"></option>
                            </template>
                        </select>
                    </label>
                </template>
            </div>
            <label class="fibre-code__field fibre-code__field--mark">{{ __('fibre_code.mark_name') }}
                <input class="fibre-code__input fibre-code__input--text" type="text" maxlength="40" x-bind:value="customMark" x-on:input="setCustomMark($event)">
            </label>
        </div>
    </section>

    <div class="fibre-code__grid">
        <section class="fibre-code__block">
            <h2 class="fibre-code__label">{{ __('fibre_code.number_to_tube') }}</h2>
            <div class="fibre-code__row">
                <label class="fibre-code__field">{{ __('fibre_code.fibre_number') }}
                    <input class="fibre-code__input fibre-code__input--big" type="number" min="1" x-bind:value="number" x-on:input="setNumber($event)">
                </label>
            </div>
            <div class="fibre-code__result">
                <p class="fibre-code__error" x-show="numberError" x-text="numberError"></p>
                <template x-for="line in numberLines" x-bind:key="line.key">
                    @include('fibre-code.partials.line')
                </template>
            </div>
        </section>

        <section class="fibre-code__block">
            <h2 class="fibre-code__label">{{ __('fibre_code.tube_to_number') }}</h2>
            <div class="fibre-code__row">
                <label class="fibre-code__field">{{ __('fibre_code.tube_number') }}
                    <input class="fibre-code__input fibre-code__input--short" type="number" min="1" x-bind:value="tube" x-on:input="setTube($event)">
                </label>
                <label class="fibre-code__field">{{ __('fibre_code.fibre_in_tube') }}
                    <input class="fibre-code__input fibre-code__input--short" type="number" min="1" x-bind:value="fibre" x-on:input="setFibre($event)">
                </label>
            </div>
            <h3 class="fibre-code__label fibre-code__label--picker">{{ __('fibre_code.pick_tube') }}</h3>
            <div class="fibre-code__picker">
                <template x-for="choice in tubeChoices" x-bind:key="choice.position">
                    <button type="button" x-bind:aria-pressed="choice.pressed" x-on:click="pickTube(choice.position)"><span x-bind:class="choice.dotClass" x-bind:title="choice.title"></span><span x-text="choice.position"></span></button>
                </template>
            </div>
            <h3 class="fibre-code__label fibre-code__label--picker">{{ __('fibre_code.pick_fibre') }}</h3>
            <div class="fibre-code__picker">
                <template x-for="choice in fibreChoices" x-bind:key="choice.position">
                    <button type="button" x-bind:aria-pressed="choice.pressed" x-on:click="pickFibre(choice.position)"><span x-bind:class="choice.dotClass" x-bind:title="choice.title"></span><span x-text="choice.position"></span></button>
                </template>
            </div>
            <div class="fibre-code__result">
                <p class="fibre-code__error" x-show="tubeFibreError" x-text="tubeFibreError"></p>
                <div class="fibre-code__line" x-show="tubeFibreValid">
                    <span class="fibre-code__line-key">{{ __('fibre_code.columns.number') }}</span>
                    <span class="fibre-code__big-number" x-text="encodedNumber"></span>
                </div>
                <template x-for="line in tubeFibreLines" x-bind:key="line.key">
                    @include('fibre-code.partials.line')
                </template>
            </div>
        </section>
    </div>

    <section class="fibre-code__block">
        <h2 class="fibre-code__label">{{ __('fibre_code.legend') }} · <span x-text="codeName"></span></h2>
        <div class="fibre-code__legend">
            <template x-for="item in legend" x-bind:key="item.position">
                <div class="fibre-code__legend-item">
                    <span class="fibre-code__position" x-text="item.position"></span>
                    <span x-bind:class="item.dotClass" x-bind:title="item.colour.label"></span>
                    <span x-text="item.name"></span>
                </div>
            </template>
        </div>
    </section>

    <section class="fibre-code__block">
        <div class="fibre-code__table-bar">
            <h2 class="fibre-code__label">{{ __('fibre_code.table') }}</h2>
            <button type="button" @class(['fibre-code__copy', 'btn btn--ghost' => ! $standalone]) x-on:click="copy" x-text="copyText"></button>
        </div>
        <div class="fibre-code__table-wrap">
            <table class="fibre-code__table">
                <thead>
                    <tr>
                        <th>{{ __('fibre_code.columns.number') }}</th>
                        <th>{{ __('fibre_code.columns.tube') }}</th>
                        <th>{{ __('fibre_code.columns.tube_colour') }}</th>
                        <th>{{ __('fibre_code.columns.fibre') }}</th>
                        <th>{{ __('fibre_code.columns.fibre_colour') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="row in rows" x-bind:key="row.number">
                        <tr x-bind:class="rowClass(row)">
                            <td class="fibre-code__number" x-text="row.number"></td>
                            <td class="fibre-code__number" x-text="row.tube"></td>
                            <td><span class="fibre-code__cell"><span x-bind:class="row.tubeDotClass" x-bind:title="row.tubeColour.label"></span><span x-text="row.tubeColour.label"></span></span></td>
                            <td class="fibre-code__number" x-text="row.fibre"></td>
                            <td><span class="fibre-code__cell"><span x-bind:class="row.fibreDotClass" x-bind:title="row.fibreColour.label"></span><span x-text="row.fibreColour.label"></span></span></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </section>
</div>
