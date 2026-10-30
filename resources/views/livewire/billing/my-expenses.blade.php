<div wire:poll.5s.visible>
    <form class="billing__card" wire:submit="save">
        <p class="billing__card-title billing__card-title--strong">{{ $editingId ? __('billing.expenses.edit_title') : __('billing.expenses.new_title') }}</p>
        <div class="billing__grid-auto">
            <div>
                <label class="billing__label billing__label--small" for="expense-date">{{ __('billing.expenses.date') }}</label>
                <input class="billing__input" type="date" id="expense-date" wire:model="date">
            </div>
            <div>
                <label class="billing__label billing__label--small" for="expense-category">{{ __('billing.expenses.category') }}</label>
                <select class="billing__input" id="expense-category" wire:model.live="category">
                    @foreach ($categories as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="billing__label billing__label--small" for="expense-amount">{{ __('billing.expenses.amount') }}</label>
                <input class="billing__input" type="number" step="0.01" min="0" id="expense-amount" wire:model="amount">
            </div>
        </div>
        @if ($category === 'other')
            <div class="billing__block">
                <label class="billing__label billing__label--small" for="expense-other">{{ __('billing.expenses.other') }}</label>
                <input class="billing__input" type="text" id="expense-other" wire:model="categoryOther" maxlength="120" placeholder="{{ __('billing.expenses.other_placeholder') }}">
            </div>
        @endif
        @if ($category === 'fuel')
            <div class="billing__block">
                <label class="billing__label billing__label--small" for="expense-mileage">{{ __('billing.expenses.mileage') }}</label>
                <input class="billing__input" type="number" step="1" min="0" id="expense-mileage" wire:model="mileage" placeholder="{{ __('billing.expenses.mileage_placeholder') }}">
            </div>
        @endif
        <div class="billing__block">
            <label class="billing__label billing__label--small" for="expense-comment">{{ __('billing.expenses.comment') }}</label>
            <textarea class="billing__input" id="expense-comment" rows="2" wire:model="comment"></textarea>
        </div>
        <div class="billing__block">
            <span class="billing__label">{{ __('billing.expenses.receipt') }}</span>
            <label class="file-picker" x-data="photoPicker" data-property="incoming.receipts" data-pick="{{ __('billing.form.photos_pick') }}" data-sending="{{ __('billing.form.photos_sending') }}">
                <span class="billing__button billing__button--small" x-text="label">{{ __('billing.form.photos_pick') }}</span>
                <input class="file-picker__input" type="file" accept="image/jpeg,image/png,image/webp" multiple x-on:change="pick" x-bind:disabled="busy">
            </label>
            @error('incoming.receipts.*') <p class="billing__error" role="alert">{{ __('billing.form.photos_failed') }}</p> @enderror
            @if ($existingPhotos->isNotEmpty() || $newPhotos !== [])
                <div class="billing__thumbs">
                    @foreach ($existingPhotos as $photo)
                        <span class="billing__thumb" wire:key="saved-receipt-{{ $photo->id }}">
                            <img src="{{ route('billing.expense-photos', [$photo->id, 'thumb']) }}" alt="">
                            <button type="button" wire:click="removePhoto({{ $photo->id }})" aria-label="{{ __('billing.form.photo_remove') }}">✕</button>
                        </span>
                    @endforeach
                    @foreach ($newPhotos as $index => $photo)
                        <span class="billing__thumb" wire:key="new-receipt-{{ $index }}">
                            @if ($photo->isPreviewable())
                                <img src="{{ $photo->temporaryUrl() }}" alt="">
                            @endif
                            <button type="button" wire:click="removeNewPhoto({{ $index }})" aria-label="{{ __('billing.form.photo_remove') }}">✕</button>
                        </span>
                    @endforeach
                </div>
            @endif
        </div>
        @if ($error !== '')
            <p class="billing__error" role="alert">{{ $error }}</p>
        @endif
        <div class="billing__actions">
            <button type="submit" class="billing__button billing__button--grow">{{ $editingId ? '✓ '.__('billing.form.save_changes') : '+ '.__('billing.expenses.add') }}</button>
            @if ($editingId)
                <button type="button" class="billing__button" wire:click="cancelEdit">{{ __('billing.form.cancel') }}</button>
            @endif
        </div>
    </form>

    <p class="billing__section-title billing__section-title--strong">{{ __('billing.expenses.mine') }}</p>
    @if ($expenses->isEmpty())
        <p class="billing__muted">{{ __('billing.expenses.none') }}</p>
    @else
        <div class="billing__entries">
            @foreach ($expenses as $expense)
                @include('livewire.billing.partials.expense-row', ['expense' => $expense, 'editable' => true])
            @endforeach
        </div>
    @endif

    @if ($detail)
        @include('livewire.billing.partials.expense-detail', ['expense' => $detail])
    @endif
</div>
