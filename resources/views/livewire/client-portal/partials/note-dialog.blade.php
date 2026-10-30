<div class="portal-modal" wire:click.self="closeNoteDialog">
    <form class="portal-modal__card" role="dialog" aria-modal="true" wire:submit="sendDialogNote">
        <h3>📝 {{ __('client_portal.notes.dialog_title') }}</h3>
        <div class="portal-modal__sub">{{ __('client_portal.notes.box_label', ['box' => $noteBoxName]) }} · {{ $current['cabinet'] }}</div>
        <textarea class="portal-textarea" rows="5" maxlength="2000" wire:model="noteText" placeholder="{{ __('client_portal.notes.dialog_placeholder') }}" autofocus></textarea>
        @error('noteText') <p class="portal-field__error">{{ $message }}</p> @enderror
        <div class="portal-modal__hint">{{ __('client_portal.notes.dialog_hint', ['company' => $company]) }}</div>
        <div class="portal-modal__actions">
            <button type="button" class="portal-btn portal-btn--ghost" wire:click="closeNoteDialog">{{ __('client_portal.notes.cancel') }}</button>
            <button type="submit" class="portal-btn">{{ __('client_portal.notes.dialog_send') }}</button>
        </div>
    </form>
</div>
