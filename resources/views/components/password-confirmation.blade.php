@props(['open' => false, 'id'])

@if ($open)
    <div class="overlay" wire:keydown.escape="cancelPasswordConfirmation">
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="{{ $id }}-title">
            <h2 id="{{ $id }}-title">{{ __('sessions.confirm_password.title') }}</h2>
            <p class="page-hint">{{ __('sessions.confirm_password.hint') }}</p>

            <form wire:submit="confirmPassword" novalidate>
                <div class="field">
                    <label class="field__label" for="{{ $id }}-password">{{ __('sessions.confirm_password.password') }}</label>
                    <input class="field__input" type="password" id="{{ $id }}-password" wire:model="confirmablePassword" autocomplete="current-password" autofocus>
                    @error('confirmablePassword') <p class="field__error">{{ $message }}</p> @enderror
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn--ghost" wire:click="cancelPasswordConfirmation">{{ __('sessions.confirm_password.cancel') }}</button>
                    <button type="submit" class="btn btn--primary" wire:loading.attr="disabled" wire:target="confirmPassword">{{ __('sessions.confirm_password.confirm') }}</button>
                </div>
            </form>
        </div>
    </div>
@endif
