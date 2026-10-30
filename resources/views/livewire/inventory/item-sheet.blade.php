<div>
    @if ($open)
        <div class="overlay overlay--sheet" wire:click.self="close" wire:keydown.escape="close">
            <div class="modal modal--wide" role="dialog" aria-modal="true" aria-labelledby="inventory-sheet-title">
                @if ($action === 'create')
                    <h2 id="inventory-sheet-title">{{ __('inventory.new_equipment') }}</h2>
                @elseif ($item)
                    <h2 id="inventory-sheet-title">{{ $item->label }}</h2>
                @endif

                @error('form') <div class="field-warning" role="alert">{{ $message }}</div> @enderror

                @if ($action === '' && $item)
                    <div class="inventory__chips">
                        @if ($item->trashed())
                            <span class="inventory__chip inventory__chip--deleted">{{ __('inventory.deleted') }}</span>
                        @else
                            <span class="inventory__chip inventory__chip--{{ $item->status->value }}">{{ $item->status->label() }}</span>
                        @endif
                        @if ($item->needs_check)
                            <span class="inventory__chip inventory__chip--to_confirm">⚠ {{ __('inventory.to_check') }}</span>
                        @endif
                    </div>

                    <dl class="inventory-sheet__list">
                        <dt>{{ __('inventory.fields.id') }}</dt><dd>{{ $item->reference() }}</dd>
                        @if ($item->category)<dt>{{ __('inventory.fields.category') }}</dt><dd>{{ __('enums.inventory_categories.'.$item->category) }}</dd>@endif
                        @if ($item->brand || $item->model)<dt>{{ __('inventory.fields.brand_model') }}</dt><dd>{{ collect([$item->brand, $item->model])->filter()->implode(' / ') }}</dd>@endif
                        <dt>{{ __('inventory.fields.serial') }}</dt><dd>{{ $item->serial ?: ($item->is_serialized ? __('inventory.no_serial') : '—') }}</dd>
                        <dt>{{ __('inventory.fields.quantity') }}</dt><dd>{{ $item->quantity }}</dd>
                        <dt>{{ __('inventory.fields.condition') }}</dt><dd>{{ $item->condition->label() }}</dd>
                        @if ($item->holder)<dt>{{ __('inventory.fields.holder') }}</dt><dd>{{ $item->holder->name }}@if ($item->holder->vehicle?->model) · 🚐 {{ $item->holder->vehicle->model }}@endif</dd>@endif
                        @if ($item->holder && $item->assigned_on)<dt>{{ __('inventory.fields.assigned_on') }}</dt><dd>{{ $item->assigned_on->translatedFormat('d/m/Y') }}</dd>@endif
                        @if ($item->noted_on || $item->site)<dt>{{ __('inventory.fields.noted') }}</dt><dd>{{ collect([$item->noted_on?->translatedFormat('d/m/Y'), $item->site])->filter()->implode(' — ') }}</dd>@endif
                        @if ($item->source)<dt>{{ __('inventory.fields.source') }}</dt><dd>{{ $item->source }}</dd>@endif
                        @if ($item->vehicle)<dt>{{ __('inventory.fields.vehicle') }}</dt><dd>{{ $item->vehicle }}</dd>@endif
                        @if ($item->uncertainty)<dt>{{ __('inventory.fields.uncertainty') }}</dt><dd>{{ $item->uncertainty }}</dd>@endif
                        @if ($item->notes)<dt>{{ __('inventory.fields.notes') }}</dt><dd>{{ $item->notes }}</dd>@endif
                        @if ($item->anomaly)<dt>{{ __('inventory.fields.anomaly') }}</dt><dd>{{ __('enums.inventory_anomaly_kinds.'.$item->anomaly['kind']) }} — {{ $item->anomaly['comment'] }} <span class="inventory__muted">{{ $item->anomaly['by_name'] }}</span></dd>@endif
                        @if ($item->pending_request)<dt>{{ __('inventory.fields.request') }}</dt><dd>{{ __('enums.inventory_request_kinds.'.$item->pending_request['kind']) }}@if ($item->pending_request['comment']) — {{ $item->pending_request['comment'] }}@endif <span class="inventory__muted">{{ $item->pending_request['by_name'] }}</span></dd>@endif
                    </dl>

                    @if ($item->trashed())
                        <p class="inventory__muted">{{ __('inventory.deleted_by', ['name' => $item->deleter?->name ?? '—', 'date' => $item->deleted_at?->setTimezone(config('platform.timezone'))->translatedFormat('d/m/Y')]) }}@if ($item->deleted_comment) — « {{ $item->deleted_comment }} »@endif</p>
                    @endif

                    <h3 class="inventory__section-title">{{ __('inventory.photos') }}</h3>
                    <div class="inventory-sheet__photos">
                        @forelse ($item->photos as $photo)
                            <div class="inventory-sheet__photo" wire:key="photo-{{ $photo->id }}">
                                <button type="button" class="inventory-sheet__thumb" wire:click="zoom({{ $photo->id }})" aria-label="{{ __('inventory.zoom') }}">
                                    <img src="{{ route('vehicle-images.show', ['type' => 'inventory', 'id' => $photo->id, 'variant' => 'thumb']) }}" alt="" loading="lazy">
                                </button>
                                @if ($photo->kind === 'proof')<small class="inventory__muted">{{ __('inventory.proof') }}</small>@endif
                                @if ($canManagePhotos)
                                    <span class="inventory-sheet__photo-actions">
                                        <label class="file-picker" wire:click="startReplace({{ $photo->id }})" title="{{ __('inventory.replace_photo') }}">
                                            <input class="file-picker__input" type="file" wire:model="replacement" accept="image/jpeg,image/png,image/webp">
                                            <span class="btn btn--ghost">🔄</span>
                                        </label>
                                        <button type="button" class="btn btn--ghost btn--danger" wire:click="removePhoto({{ $photo->id }})" wire:confirm="{{ __('inventory.confirm_remove_photo') }}" title="{{ __('inventory.remove_photo') }}">✕</button>
                                    </span>
                                @endif
                            </div>
                        @empty
                            <p class="inventory__muted">{{ __('inventory.no_photo') }}</p>
                        @endforelse
                    </div>
                    @if ($canAddPhotos)
                        <label class="file-picker">
                            <input class="file-picker__input" type="file" wire:model="newPhotos" accept="image/jpeg,image/png,image/webp" multiple>
                            <span class="btn btn--ghost">📷 {{ __('inventory.add_photos') }}</span>
                        </label>
                    @endif
                    @error('newPhotos') <p class="field__error">{{ $message }}</p> @enderror
                    @error('newPhotos.*') <p class="field__error">{{ $message }}</p> @enderror
                    @error('replacement') <p class="field__error">{{ $message }}</p> @enderror

                    <div class="inventory-sheet__actions">
                        @foreach ($actions as $name)
                            @if ($name === 'confirm')
                                <button type="button" class="btn btn--primary" wire:click="confirmHandover" wire:confirm="{{ __('inventory.confirm_handover', ['label' => $item->label, 'name' => $item->holder?->name]) }}">✔ {{ __('inventory.actions.confirm') }}</button>
                            @elseif ($name === 'restore')
                                <button type="button" class="btn btn--primary" wire:click="restore">{{ __('inventory.actions.restore') }}</button>
                            @elseif ($name === 'close_request')
                                <button type="button" class="btn btn--ghost" wire:click="closeRequest">{{ __('inventory.actions.close_request') }}</button>
                            @elseif ($name === 'delete')
                                <button type="button" class="btn btn--ghost btn--danger" wire:click="startAction('delete')">{{ __('inventory.actions.delete') }}</button>
                            @else
                                <button type="button" class="btn btn--ghost" wire:click="startAction('{{ $name }}')">{{ __('inventory.actions.'.$name) }}</button>
                            @endif
                        @endforeach
                    </div>

                    <h3 class="inventory__section-title">{{ __('inventory.history') }}</h3>
                    <div class="inventory-sheet__history">
                        @forelse ($moves as $move)
                            <div wire:key="sheet-move-{{ $move->id }}"><strong>{{ $move->type->label() }}</strong>
                                @if ($move->from_name && $move->to_name) {{ __('inventory.move_from_to', ['from' => $move->from_name, 'to' => $move->to_name]) }}@elseif ($move->to_name) {{ __('inventory.move_to', ['to' => $move->to_name]) }}@elseif ($move->from_name) {{ __('inventory.move_from', ['from' => $move->from_name]) }}@endif
                                @if ($move->comment)<em>« {{ $move->comment }} »</em>@endif
                                <small class="inventory__muted">{{ $move->created_at->setTimezone(config('platform.timezone'))->translatedFormat('d/m/Y H:i') }} · {{ __('inventory.by', ['name' => $move->user_name]) }}</small>
                            </div>
                        @empty
                            <p class="inventory__muted">{{ __('inventory.no_movement') }}</p>
                        @endforelse
                    </div>

                    <div class="modal-actions">
                        <button type="button" class="btn btn--ghost" wire:click="close">{{ __('inventory.close') }}</button>
                    </div>
                @elseif ($action !== '')
                    <form wire:submit="submit" novalidate>
                        @if ($item)
                            <p class="inventory__muted">{{ $item->label }}{{ $item->serial ? ' · '.$item->serial : '' }}</p>
                            <p class="inventory__hint">{{ __('inventory.action_hint.'.$action) }}</p>
                        @endif

                        @if (in_array($action, ['create', 'edit'], true))
                            <div class="field">
                                <label class="field__label" for="f-label">{{ __('inventory.fields.label') }} *</label>
                                <input class="field__input" id="f-label" wire:model="form.label" maxlength="120">
                                @error('form.label') <p class="field__error">{{ $message }}</p> @enderror
                            </div>
                            <div class="field-row">
                                <div class="field">
                                    <label class="field__label" for="f-category">{{ __('inventory.fields.category') }}</label>
                                    <select class="field__input" id="f-category" wire:model="form.category">
                                        <option value="">—</option>
                                        @foreach ($categories as $category)
                                            <option value="{{ $category->value }}">{{ $category->label() }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="field">
                                    <label class="field__label" for="f-condition">{{ __('inventory.fields.condition') }}</label>
                                    <select class="field__input" id="f-condition" wire:model="form.condition">
                                        @foreach ($conditions as $condition)
                                            <option value="{{ $condition->value }}">{{ $condition->label() }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="field-row">
                                <div class="field"><label class="field__label" for="f-brand">{{ __('inventory.fields.brand') }}</label><input class="field__input" id="f-brand" wire:model="form.brand" maxlength="60"></div>
                                <div class="field"><label class="field__label" for="f-model">{{ __('inventory.fields.model') }}</label><input class="field__input" id="f-model" wire:model="form.model" maxlength="60"></div>
                            </div>
                            <div class="field-row">
                                <div class="field"><label class="field__label" for="f-serial">{{ __('inventory.fields.serial') }}</label><input class="field__input" id="f-serial" wire:model="form.serial" maxlength="80"></div>
                                <div class="field">
                                    <label class="field__label" for="f-quantity">{{ __('inventory.fields.quantity') }}</label>
                                    <input class="field__input" type="number" min="1" max="9999" id="f-quantity" wire:model="form.quantity" @disabled(! empty($form['serial']) || ($item?->is_serialized ?? false))>
                                    @error('form.quantity') <p class="field__error">{{ $message }}</p> @enderror
                                </div>
                            </div>
                            <div class="field"><label class="field__label" for="f-vehicle">{{ __('inventory.fields.vehicle') }}</label><input class="field__input" id="f-vehicle" wire:model="form.vehicle" maxlength="60"></div>
                        @endif

                        @if ($action === 'create')
                            <div class="field-row">
                                <div class="field">
                                    <label class="field__label" for="f-holder">{{ __('inventory.fields.holder') }}</label>
                                    <select class="field__input" id="f-holder" wire:model="form.holder">
                                        <option value="">{{ __('inventory.no_holder') }}</option>
                                        @foreach ($people as $person)
                                            <option value="{{ $person->id }}">{{ $person->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="field">
                                    <label class="field__label" for="f-initial">{{ __('inventory.fields.initial') }}</label>
                                    <select class="field__input" id="f-initial" wire:model="form.initial">
                                        <option value="to_confirm">{{ __('inventory.initial.to_confirm') }}</option>
                                        <option value="official">{{ __('inventory.initial.official') }}</option>
                                    </select>
                                </div>
                            </div>
                            <div class="field-row">
                                <div class="field"><label class="field__label" for="f-site">{{ __('inventory.fields.site') }}</label><input class="field__input" id="f-site" wire:model="form.site" maxlength="80"></div>
                                <div class="field"><label class="field__label" for="f-date">{{ __('inventory.fields.date') }}</label><input class="field__input" type="date" id="f-date" wire:model="form.date"></div>
                            </div>
                        @endif

                        @if (in_array($action, ['create', 'edit'], true))
                            <label class="expenses__check"><input type="checkbox" wire:model="form.needs_check"><span>{{ __('inventory.to_check') }}</span></label>
                            <div class="field"><label class="field__label" for="f-uncertainty">{{ __('inventory.fields.uncertainty') }}</label><input class="field__input" id="f-uncertainty" wire:model="form.uncertainty" maxlength="400"></div>
                            <div class="field"><label class="field__label" for="f-notes">{{ __('inventory.fields.notes') }}</label><textarea class="field__input" id="f-notes" rows="2" wire:model="form.notes" maxlength="600"></textarea></div>
                        @endif

                        @if (in_array($action, ['assign', 'transfer'], true))
                            <div class="field">
                                <label class="field__label" for="f-recipient">{{ __('inventory.fields.recipient') }} *</label>
                                <select class="field__input" id="f-recipient" wire:model="form.recipient">
                                    <option value="">—</option>
                                    @foreach ($people as $person)
                                        <option value="{{ $person->id }}">{{ $person->name }}</option>
                                    @endforeach
                                </select>
                                @error('form.recipient') <p class="field__error">{{ $message }}</p> @enderror
                            </div>
                        @endif

                        @if (in_array($action, ['assign', 'return', 'transfer', 'anomaly'], true) && $item && $item->quantity > 1 && ! $item->is_serialized)
                            <div class="field">
                                <label class="field__label" for="f-qty">{{ __('inventory.fields.quantity') }} ({{ __('inventory.max', ['max' => $item->quantity]) }})</label>
                                <input class="field__input field__input--narrow" type="number" min="1" max="{{ $item->quantity }}" id="f-qty" wire:model="form.quantity">
                                @error('form.quantity') <p class="field__error">{{ $message }}</p> @enderror
                            </div>
                        @endif

                        @if (in_array($action, ['assign', 'return', 'transfer'], true))
                            <div class="field">
                                <label class="field__label" for="f-cond">{{ __('inventory.fields.condition') }}</label>
                                <select class="field__input" id="f-cond" wire:model="form.condition">
                                    @foreach ($conditions as $condition)
                                        <option value="{{ $condition->value }}">{{ $condition->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif

                        @if ($action === 'assign')
                            <div class="field"><label class="field__label" for="f-adate">{{ __('inventory.fields.date') }}</label><input class="field__input field__input--narrow" type="date" id="f-adate" wire:model="form.date"></div>
                        @endif

                        @if ($action === 'return')
                            <div class="field">
                                <label class="field__label" for="f-outcome">{{ __('inventory.fields.after_return') }}</label>
                                <select class="field__input" id="f-outcome" wire:model="form.outcome">
                                    @foreach (['stock', 'repair', 'damaged', 'retired'] as $outcome)
                                        <option value="{{ $outcome }}">{{ __('inventory.outcomes.return.'.$outcome) }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif

                        @if ($action === 'resolve')
                            <div class="field">
                                <label class="field__label" for="f-resolve">{{ __('inventory.fields.after_return') }}</label>
                                <select class="field__input" id="f-resolve" wire:model="form.outcome">
                                    @foreach (['assigned', 'stock', 'repair', 'retired'] as $outcome)
                                        @continue($outcome === 'assigned' && ! $item?->holder_id)
                                        <option value="{{ $outcome }}">{{ __('inventory.outcomes.resolve.'.$outcome) }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif

                        @if ($action === 'anomaly')
                            <div class="field">
                                <label class="field__label" for="f-kind">{{ __('inventory.fields.type') }}</label>
                                <select class="field__input" id="f-kind" wire:model="form.kind">
                                    @foreach ($anomalyKinds as $kind)
                                        <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif

                        @if ($action === 'request')
                            <div class="field">
                                <label class="field__label" for="f-rkind">{{ __('inventory.fields.type') }}</label>
                                <select class="field__input" id="f-rkind" wire:model="form.kind">
                                    @foreach ($requestKinds as $kind)
                                        <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif

                        @if (in_array($action, ['return', 'anomaly'], true))
                            <div class="field">
                                <label class="file-picker">
                                    <input class="file-picker__input" type="file" wire:model="proofs" accept="image/jpeg,image/png,image/webp" multiple>
                                    <span class="btn btn--ghost">📷 {{ __('inventory.proof_photos') }}</span>
                                    @if (count($proofs) > 0)<span class="file-picker__name">✓ {{ trans_choice('inventory.photos_ready', count($proofs), ['count' => count($proofs)]) }}</span>@endif
                                </label>
                                @error('proofs') <p class="field__error">{{ $message }}</p> @enderror
                                @error('proofs.*') <p class="field__error">{{ $message }}</p> @enderror
                            </div>
                        @endif

                        @if (in_array($action, ['return', 'transfer', 'anomaly', 'request', 'resolve', 'delete'], true))
                            <div class="field">
                                <label class="field__label" for="f-comment">{{ __('inventory.fields.comment') }}@if ($action === 'anomaly') *@endif</label>
                                <textarea class="field__input" id="f-comment" rows="2" wire:model="form.comment" maxlength="600"></textarea>
                                @error('form.comment') <p class="field__error">{{ $message }}</p> @enderror
                            </div>
                        @endif

                        <div class="modal-actions">
                            <button type="button" class="btn btn--ghost" wire:click="{{ $action === 'create' ? 'close' : 'cancelAction' }}">{{ __('inventory.cancel') }}</button>
                            <button type="submit" @class(['btn', 'btn--primary', 'btn--danger' => $action === 'delete']) wire:loading.attr="disabled" wire:target="submit">{{ __('inventory.ok') }}</button>
                        </div>
                    </form>
                @endif
            </div>
        </div>
    @endif

    @if ($open && $zoom)
        <div class="overlay inventory-zoom" wire:click="closeZoom" wire:keydown.escape="closeZoom">
            <img class="inventory-zoom__image" src="{{ route('vehicle-images.show', ['type' => 'inventory', 'id' => $zoom->id, 'variant' => 'full']) }}" alt="">
        </div>
    @endif
</div>
