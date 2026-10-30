<div>
    @if ($open && $punch)
        <div class="overlay" wire:click.self="close" wire:keydown.escape="close">
            <div class="modal" role="dialog" aria-modal="true" aria-labelledby="declare-title">
                <h2 id="declare-title">{{ __('day_punch.declare.title') }}</h2>
                <p>{{ __('day_punch.declare.text', ['date' => $punch->date->translatedFormat('l d F'), 'time' => $punch->startLocal()]) }}</p>

                <form wire:submit="declare" novalidate>
                    <div class="field">
                        <label class="field__label" for="declare-time">{{ __('day_punch.declare.time') }}</label>
                        <input class="field__input field__input--narrow" type="time" id="declare-time" wire:model="time">
                        @error('time') <p class="field__error">{{ $message }}</p> @enderror
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn btn--ghost" wire:click="close">{{ __('day_punch.declare.cancel') }}</button>
                        <button type="submit" class="btn btn--primary">{{ __('day_punch.declare.submit') }}</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
