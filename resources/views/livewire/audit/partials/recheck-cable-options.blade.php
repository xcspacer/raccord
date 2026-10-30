<option value="">{{ $placeholder }}</option>
@if ($cables['box'] !== [])
    <optgroup label="{{ __('audit.recheck.group_box') }}">
        @foreach ($cables['box'] as $name) <option value="{{ $name }}" @selected($name === $selected)>{{ $name }}</option> @endforeach
    </optgroup>
@endif
@if ($cables['plan'] !== [])
    <optgroup label="{{ __('audit.recheck.group_plan') }}">
        @foreach ($cables['plan'] as $name) <option value="{{ $name }}" @selected($name === $selected)>{{ \App\Audit\Recheck\BoxRecheck::compactCable($name) }}</option> @endforeach
    </optgroup>
@endif
@if ($cables['manual'] !== [])
    <optgroup label="{{ __('audit.recheck.group_manual') }}">
        @foreach ($cables['manual'] as $name) <option value="{{ $name }}" @selected($name === $selected)>{{ $name }}</option> @endforeach
    </optgroup>
@endif
@if ($selected !== '' && ! in_array($selected, [...$cables['box'], ...$cables['plan'], ...$cables['manual']], true))
    <option value="{{ $selected }}" selected>{{ \App\Audit\Recheck\BoxRecheck::compactCable($selected) }}</option>
@endif
@if ($manual ?? false)
    <option value="__manual">{{ __('audit.recheck.manual_cable') }}</option>
@endif
