<div>
    @if ($open)
        <div class="overlay overlay--sheet" wire:click.self="close" wire:keydown.escape="close">
            <div class="modal modal--wide" role="dialog" aria-modal="true" aria-labelledby="bulk-title">
                <h2 id="bulk-title">{{ $mode === 'photos' ? __('inventory.bulk.photos_title') : __('inventory.bulk.import_title') }}</h2>
                <p class="inventory__hint">{{ $mode === 'photos' ? __('inventory.bulk.photos_hint') : __('inventory.bulk.import_hint') }}</p>
                @if ($mode === 'photos')<p class="inventory__hint"><strong>{{ __('inventory.bulk.one_unit') }}</strong></p><x-ai-notice />@endif

                @error('form') <div class="field-warning" role="alert">{{ $message }}</div> @enderror

                <div class="field">
                    <label class="field__label" for="bulk-holder">{{ __('inventory.fields.holder') }}</label>
                    <select class="field__input" id="bulk-holder" wire:model="holder" @disabled($analysed)>
                        <option value="">{{ __('inventory.no_holder') }}</option>
                        @foreach ($people as $person)
                            <option value="{{ $person->id }}">{{ $person->name }}</option>
                        @endforeach
                    </select>
                </div>

                @if (! $analysed)
                    @if ($mode === 'photos')
                        <label class="file-picker">
                            <input class="file-picker__input" type="file" wire:model="photos" accept="image/jpeg,image/png,image/webp" multiple>
                            <span class="btn btn--ghost">📷 {{ __('inventory.bulk.choose_photos', ['max' => $maxPhotos]) }}</span>
                            @if (count($photos) > 0)<span class="file-picker__name">✓ {{ trans_choice('inventory.photos_ready', count($photos), ['count' => count($photos)]) }}</span>@endif
                        </label>
                        @error('photos') <p class="field__error">{{ $message }}</p> @enderror
                        @error('photos.*') <p class="field__error">{{ $message }}</p> @enderror
                        <div class="modal-actions">
                            <button type="button" class="btn btn--ghost" wire:click="close">{{ __('inventory.cancel') }}</button>
                            <button type="button" class="btn btn--primary" wire:click="analyse" wire:loading.attr="disabled" wire:target="analyse,photos" @disabled(count($photos) === 0)>
                                <span wire:loading.remove wire:target="analyse">{{ __('inventory.bulk.analyse') }}</span>
                                <span wire:loading wire:target="analyse">{{ __('inventory.bulk.analysing') }}</span>
                            </button>
                        </div>
                    @else
                        <label class="file-picker">
                            <input class="file-picker__input" type="file" wire:model="listFile" accept=".txt,.csv,.md">
                            <span class="btn btn--ghost">📄 {{ __('inventory.bulk.choose_file') }}</span>
                        </label>
                        @error('listFile') <p class="field__error">{{ $message }}</p> @enderror
                        <div class="field">
                            <label class="field__label" for="bulk-text">{{ __('inventory.bulk.table') }}</label>
                            <textarea class="field__input" id="bulk-text" rows="8" wire:model="text"></textarea>
                            @error('text') <p class="field__error">{{ $message }}</p> @enderror
                        </div>
                        <div class="modal-actions">
                            <button type="button" class="btn btn--ghost" wire:click="close">{{ __('inventory.cancel') }}</button>
                            <button type="button" class="btn btn--primary" wire:click="parse">{{ __('inventory.bulk.read_table') }}</button>
                        </div>
                    @endif
                @else
                    <h3 class="inventory__section-title">{{ __('inventory.bulk.preview') }}</h3>
                    @foreach ($warnings as $warning)
                        <div class="field-warning" role="status">⚠ {{ $warning }}</div>
                    @endforeach

                    @foreach ($rows as $index => $row)
                        <article @class(['inventory__proposal', 'is-excluded' => empty($row['include'])]) wire:key="row-{{ $index }}">
                            <label class="expenses__check"><input type="checkbox" wire:model.live="rows.{{ $index }}.include"><span>{{ $row['label'] ?: __('inventory.bulk.new_line') }}</span></label>
                            @if (! empty($row['confidence']))<span class="inventory__chip">{{ __('inventory.confidence.'.$row['confidence']) }}</span>@endif
                            @if (! empty($row['match']))<p class="inventory__muted">{{ __('inventory.bulk.already', ['reference' => $row['match']]) }}</p>@endif
                            <div class="field-row">
                                <div class="field"><label class="field__label">{{ __('inventory.fields.label') }}</label><input class="field__input" wire:model.blur="rows.{{ $index }}.label" maxlength="120"></div>
                                <div class="field"><label class="field__label">{{ __('inventory.fields.category') }}</label>
                                    <select class="field__input" wire:model="rows.{{ $index }}.category"><option value="">—</option>@foreach ($categories as $category)<option value="{{ $category->value }}">{{ $category->label() }}</option>@endforeach</select>
                                </div>
                            </div>
                            <div class="field-row">
                                <div class="field"><label class="field__label">{{ __('inventory.fields.brand') }}</label><input class="field__input" wire:model.blur="rows.{{ $index }}.brand" maxlength="60"></div>
                                <div class="field"><label class="field__label">{{ __('inventory.fields.model') }}</label><input class="field__input" wire:model.blur="rows.{{ $index }}.model" maxlength="60"></div>
                            </div>
                            <div class="field-row">
                                <div class="field"><label class="field__label">{{ __('inventory.fields.serial') }}</label><input class="field__input" wire:model.blur="rows.{{ $index }}.serial" maxlength="80"></div>
                                <div class="field"><label class="field__label">{{ __('inventory.fields.quantity') }}</label><input class="field__input field__input--narrow" type="number" min="1" max="9999" wire:model.blur="rows.{{ $index }}.quantity"></div>
                            </div>
                            @if (! empty($row['note']))<p class="inventory__muted">⚠ {{ $row['note'] }}</p>@endif
                            <label class="expenses__check"><input type="checkbox" wire:model="rows.{{ $index }}.needs_check"><span>{{ __('inventory.to_check') }}</span></label>
                        </article>
                    @endforeach

                    <button type="button" class="btn btn--ghost" wire:click="addRow">＋ {{ __('inventory.bulk.add_row') }}</button>

                    <div class="field-row">
                        <div class="field">
                            <label class="field__label" for="bulk-initial">{{ __('inventory.fields.initial') }}</label>
                            <select class="field__input" id="bulk-initial" wire:model="initial" @disabled($holder === '')>
                                <option value="to_confirm">{{ __('inventory.initial.to_confirm') }}</option>
                                <option value="official">{{ __('inventory.initial.official') }}</option>
                            </select>
                        </div>
                        <div class="field"><label class="field__label" for="bulk-site">{{ __('inventory.fields.site') }}</label><input class="field__input" id="bulk-site" wire:model="site" maxlength="80"></div>
                        <div class="field"><label class="field__label" for="bulk-date">{{ __('inventory.fields.date') }}</label><input class="field__input" type="date" id="bulk-date" wire:model="date"></div>
                    </div>

                    <div class="modal-actions">
                        <button type="button" class="btn btn--ghost" wire:click="close">{{ __('inventory.cancel') }}</button>
                        <button type="button" class="btn btn--primary" wire:click="save" wire:loading.attr="disabled" wire:target="save">{{ __('inventory.bulk.save') }}</button>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
