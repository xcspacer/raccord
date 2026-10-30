<div class="settings-page settings-page--list">
    <p class="page-hint">{{ __('holidays.hint') }}</p>

    <form wire:submit="save" class="settings-card" novalidate>
        <h2 class="settings-card__title">{{ $editingId ? __('holidays.edit_title') : __('holidays.add_title') }}</h2>

        <div class="settings-grid">
            <div class="field">
                <label class="field__label" for="holiday-name">{{ __('holidays.fields.name') }}</label>
                <input class="field__input" id="holiday-name" wire:model="name" maxlength="120">
                @error('name') <p class="field__error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label class="field__label" for="holiday-rule">{{ __('holidays.fields.rule') }}</label>
                <select class="field__input" id="holiday-rule" wire:model.live="rule">
                    @foreach ($rules as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </select>
            </div>

            @if ($rule === \App\Enums\HolidayRule::FixedDate->value)
                <div class="field">
                    <label class="field__label" for="holiday-day">{{ __('holidays.fields.day') }}</label>
                    <input class="field__input" type="number" id="holiday-day" min="1" max="31" wire:model="day">
                    @error('day') <p class="field__error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label class="field__label" for="holiday-month">{{ __('holidays.fields.month') }}</label>
                    <select class="field__input" id="holiday-month" wire:model="month">
                        <option value="">—</option>
                        @foreach ($months as $number => $label)
                            <option value="{{ $number }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('month') <p class="field__error" role="alert">{{ $message }}</p> @enderror
                </div>
            @elseif ($rule === \App\Enums\HolidayRule::EasterOffset->value)
                <div class="field">
                    <label class="field__label" for="holiday-offset">{{ __('holidays.fields.easter_offset') }}</label>
                    <input class="field__input" type="number" id="holiday-offset" min="-100" max="100" wire:model="easterOffset">
                    <p class="field__hint">{{ __('holidays.easter_offset_hint') }}</p>
                    @error('easterOffset') <p class="field__error" role="alert">{{ $message }}</p> @enderror
                </div>
            @else
                <div class="field">
                    <label class="field__label" for="holiday-date">{{ __('holidays.fields.date') }}</label>
                    <input class="field__input" type="date" id="holiday-date" wire:model="date">
                    @error('date') <p class="field__error" role="alert">{{ $message }}</p> @enderror
                </div>
            @endif
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary">{{ $editingId ? __('holidays.save') : __('holidays.add') }}</button>
            @if ($editingId)
                <button type="button" class="btn btn--ghost" wire:click="cancel">{{ __('holidays.cancel') }}</button>
            @endif
        </div>
    </form>

    <section class="settings-card">
        <h2 class="settings-card__title">{{ __('holidays.list_title', ['year' => $year]) }}</h2>

        @forelse ($holidays as $row)
            <div class="holiday-row" wire:key="holiday-{{ $row['holiday']->id }}">
                <span class="holiday-row__date">{{ $row['occurrence']?->translatedFormat('D d M') ?? '—' }}</span>
                <span class="holiday-row__name">{{ $row['holiday']->name }}</span>
                <span class="holiday-row__rule">{{ $row['holiday']->rule->label() }}</span>
                <button type="button" class="btn btn--ghost btn--small" wire:click="edit({{ $row['holiday']->id }})">{{ __('holidays.edit') }}</button>
                <button type="button" class="chip-delete" wire:click="delete({{ $row['holiday']->id }})" wire:confirm="{{ __('holidays.confirm_delete', ['name' => $row['holiday']->name]) }}" title="{{ __('holidays.delete') }}" aria-label="{{ __('holidays.delete') }}">✕</button>
            </div>
        @empty
            <div class="empty-state">{{ __('holidays.empty') }}</div>
        @endforelse
    </section>
</div>
