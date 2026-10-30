<div class="absence-block">
    <div>{{ $summaryTitle }} : <span class="absence-block__count">{{ $calendar['total'] }}</span> {{ __('absences.summary_counts', ['leave' => $calendar['counts']['leave'], 'sick' => $calendar['counts']['sick'], 'absent' => $calendar['counts']['absent']]) }}</div>

    @foreach ($calendar['periods'] as $period)
        <div class="absence-block__item" wire:key="period-{{ $period['start']->toDateString() }}">
            <span>{{ __('absences.icons.'.$period['type']->value) }} {{ $period['type']->label() }} — {{ $period['start']->translatedFormat('d M') }}@if (! $period['start']->eq($period['end'])) → {{ $period['end']->translatedFormat('d M') }}@endif {{ __('absences.days_short', ['count' => $period['days']]) }}</span>
            <button type="button" class="chip-delete" wire:click="askRemove('{{ $period['start']->toDateString() }}', '{{ $period['end']->toDateString() }}')" title="{{ __('absences.remove') }}" aria-label="{{ __('absences.remove') }}">✕</button>
        </div>
    @endforeach

    <form class="absence-block__add" wire:submit="add" novalidate>
        <div class="absence-block__field">
            <label for="absence-type-{{ $userId }}">{{ __('absences.fields.type') }}</label>
            <select id="absence-type-{{ $userId }}" wire:model="type">
                @foreach ($types as $type)
                    <option value="{{ $type->value }}">{{ __('absences.add_types.'.$type->value) }}</option>
                @endforeach
            </select>
        </div>
        <div class="absence-block__field">
            <label for="absence-from-{{ $userId }}">{{ __('absences.fields.from') }}</label>
            <input type="date" id="absence-from-{{ $userId }}" wire:model="from">
        </div>
        <div class="absence-block__field">
            <label for="absence-to-{{ $userId }}">{{ __('absences.fields.to') }}</label>
            <input type="date" id="absence-to-{{ $userId }}" wire:model="to">
        </div>
        <button type="submit" class="btn btn--ghost">+ {{ __('absences.add') }}</button>
    </form>
    @error('from') <p class="field__error">{{ $message }}</p> @enderror
</div>
