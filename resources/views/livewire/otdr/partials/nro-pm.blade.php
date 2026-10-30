<div class="otdr__row otdr__row--bottom otdr__nro-pm">
    <label class="otdr__field">{{ __('otdr.setup.nro_pm_length') }}
        <input inputmode="decimal" placeholder="{{ __('otdr.setup.nro_pm_placeholder') }}" x-bind:value="nroPmLength" x-on:input="setNroPm('length', $event)">
    </label>
    <label class="otdr__field">{{ __('otdr.setup.nro_pm_attenuation') }}
        <input inputmode="decimal" placeholder="{{ __('otdr.setup.nro_pm_placeholder') }}" x-bind:value="nroPmAttenuation" x-on:input="setNroPm('attenuation', $event)">
    </label>
</div>
