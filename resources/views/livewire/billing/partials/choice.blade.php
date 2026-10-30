<label @class(['billing__choice', 'is-on' => $checked])>
    <input type="radio" name="{{ $name }}" value="{{ $value }}" wire:model.live="{{ $model }}">
    <span>{{ $label }}</span>
</label>
