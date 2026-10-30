<div>
    @if ($open)
        <div class="overlay overlay--sheet" wire:click.self="close" wire:keydown.escape="close">
            <div class="modal modal--wide" role="dialog" aria-modal="true" aria-labelledby="attestation-title">
                <h2 id="attestation-title">{{ __('inventory.attestation.title') }}</h2>
                <p class="inventory__hint">{{ __('inventory.attestation.hint') }}</p>

                @error('form') <div class="field-warning" role="alert">{{ $message }}</div> @enderror

                @if ($preview)
                    <iframe class="inventory__preview" src="data:application/pdf;base64,{{ $preview }}" title="{{ __('inventory.attestation.preview') }}"></iframe>
                    <div class="modal-actions">
                        <button type="button" class="btn btn--ghost" wire:click="$set('preview', null)">{{ __('inventory.attestation.back_to_form') }}</button>
                    </div>
                @endif

                <div @if ($preview) hidden @endif>
                    <h3 class="inventory__section-title">{{ __('inventory.attestation.included') }}</h3>
                    @forelse ($lines as $line)
                        <label class="expenses__check" wire:key="att-line-{{ $line->id }}">
                            <input type="checkbox" value="{{ $line->id }}" wire:model="ids">
                            <span>{{ $line->label }}{{ $line->serial ? ' · '.$line->serial : '' }} ×{{ $line->quantity }} ({{ $line->condition->label() }})</span>
                        </label>
                    @empty
                        <p class="inventory__muted">{{ __('inventory.no_match') }}</p>
                    @endforelse

                    <div class="field-row">
                        <div class="field"><label class="field__label" for="att-date">{{ __('inventory.fields.date') }}</label><input class="field__input" type="date" id="att-date" wire:model="date">@error('date') <p class="field__error">{{ $message }}</p> @enderror</div>
                        <div class="field"><label class="field__label" for="att-rep">{{ __('inventory.attestation.representative') }}</label><input class="field__input" id="att-rep" wire:model="representative" maxlength="80"></div>
                    </div>
                    <div class="field"><label class="field__label" for="att-obs">{{ __('inventory.fields.notes') }}</label><textarea class="field__input" id="att-obs" rows="2" wire:model="observations" maxlength="800"></textarea></div>

                    <div class="field" wire:ignore>
                        <label class="field__label">{{ __('inventory.attestation.holder_signature') }}</label>
                        <canvas id="att-pad-holder" class="inventory__signature" data-signature-pad data-signature-target="att-sig-holder"></canvas>
                        <button type="button" class="btn btn--ghost" data-signature-clear="att-pad-holder">{{ __('inventory.attestation.clear') }}</button>
                    </div>
                    <input type="hidden" id="att-sig-holder" wire:model="technicianSignature">

                    <div class="field" wire:ignore>
                        <label class="field__label">{{ __('inventory.attestation.representative_signature') }}</label>
                        <canvas id="att-pad-rep" class="inventory__signature" data-signature-pad data-signature-target="att-sig-rep"></canvas>
                        <button type="button" class="btn btn--ghost" data-signature-clear="att-pad-rep">{{ __('inventory.attestation.clear') }}</button>
                    </div>
                    <input type="hidden" id="att-sig-rep" wire:model="representativeSignature">

                    @if ($unsignedWarning)
                        <div class="field-warning" role="alert">⚠ {{ __('inventory.attestation.unsigned_warning') }}</div>
                    @endif

                    <div class="modal-actions">
                        <button type="button" class="btn btn--ghost" wire:click="close">{{ __('inventory.cancel') }}</button>
                        <button type="button" class="btn btn--ghost" wire:click="showPreview" wire:loading.attr="disabled" wire:target="showPreview">{{ __('inventory.attestation.preview') }}</button>
                        @if ($unsignedWarning)
                            <button type="button" class="btn btn--danger" wire:click="archive(true)" wire:loading.attr="disabled" wire:target="archive">{{ __('inventory.attestation.archive_unsigned') }}</button>
                        @else
                            <button type="button" class="btn btn--primary" wire:click="archive(false)" wire:loading.attr="disabled" wire:target="archive">{{ __('inventory.attestation.archive') }}</button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
