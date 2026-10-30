<div>
    @if ($open && $person)
        <div class="overlay" wire:click.self="close" wire:keydown.escape="close">
            <div class="modal" role="dialog" aria-modal="true" aria-labelledby="task-title">
                <h2 id="task-title">{{ $person->name }} — {{ $title }}</h2>

                <form wire:submit="save" novalidate>
                    @error('conflict') <div class="field-warning" role="alert">{{ $message }}</div> @enderror
                    <div class="segmented" role="group" aria-label="{{ __('tasks.kind') }}">
                        @foreach (['task' => __('tasks.kinds.task'), 'absent' => __('tasks.kinds.absent'), 'sick' => __('tasks.kinds.sick'), 'leave' => __('tasks.kinds.leave')] as $value => $text)
                            @continue($value !== 'task' && ! $canManageAbsences && $mode !== $value)
                            <button type="button" @class(['segmented__option', 'is-active' => $mode === $value]) wire:click="$set('mode', '{{ $value }}')" @disabled($value !== 'task' && ! $canManageAbsences)>{{ $text }}</button>
                        @endforeach
                    </div>
                    @error('mode') <p class="field__error">{{ $message }}</p> @enderror

                    @if ($mode === 'task')
                        <div class="field">
                            <label class="field__label" for="task-state">{{ __('tasks.fields.state') }}</label>
                            <input class="field__input" id="task-state" wire:model="stateCode" maxlength="8" placeholder="{{ __('tasks.placeholders.state') }}">
                        </div>
                        <div class="field">
                            <label class="field__label" for="task-label">{{ __('tasks.fields.task') }}</label>
                            <input class="field__input" id="task-label" wire:model="label" maxlength="60" placeholder="{{ __('tasks.placeholders.task') }}">
                        </div>
                        <div class="field">
                            <label class="field__label" for="task-detail">{{ __('tasks.fields.detail') }}</label>
                            <input class="field__input" id="task-detail" wire:model="detail" maxlength="255">
                        </div>
                    @elseif ($mode !== 'absent')
                        <div class="field-row">
                            <div class="field">
                                <label class="field__label" for="task-from">{{ __('absences.fields.from') }}</label>
                                <input class="field__input" type="date" id="task-from" wire:model="from">
                            </div>
                            <div class="field">
                                <label class="field__label" for="task-to">{{ __('absences.fields.to') }}</label>
                                <input class="field__input" type="date" id="task-to" wire:model="to">
                            </div>
                        </div>
                        @error('from') <p class="field__error">{{ $message }}</p> @enderror
                    @endif

                    <div class="field">
                        <label class="field__label" for="task-note">{{ __('tasks.fields.note') }}</label>
                        <input class="field__input" id="task-note" wire:model="note" maxlength="255">
                        @error('note') <p class="field__error">{{ $message }}</p> @enderror
                    </div>

                    @if ($mode === 'task' || $canManageAbsences)
                        <div class="modal-actions modal-actions--split">
                            <button type="button" class="btn btn--ghost btn--danger" wire:click="clear">{{ __('tasks.clear') }}</button>
                            <span class="modal-actions__group">
                                <button type="button" class="btn btn--ghost" wire:click="close">{{ __('tasks.cancel') }}</button>
                                <button type="submit" class="btn btn--primary">{{ __('tasks.save') }}</button>
                            </span>
                        </div>
                    @else
                        <div class="modal-actions">
                            <button type="button" class="btn btn--ghost" wire:click="close">{{ __('tasks.cancel') }}</button>
                        </div>
                    @endif
                </form>
            </div>
        </div>
    @endif
</div>
