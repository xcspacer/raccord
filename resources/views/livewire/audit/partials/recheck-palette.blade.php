<div class="recheck__palette">
    @foreach ($palette as $position => $colour)
        <button type="button" @class(['recheck__swatch', 'is-dark' => $colour['dark'], 'recheck-colour-'.($position + 1) => true]) wire:click="setColour({{ $index }}, '{{ $field }}', {{ $position + 1 }})" title="{{ __('enums.audit_recheck_colours.'.$colour['key']) }}">{{ $position + 1 }}</button>
    @endforeach
</div>
