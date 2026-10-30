<div class="be-declare">
    @if ($canDeclare)
        <button type="button" class="btn btn--primary" wire:click="show">{{ __('design_office_workflow.declare.button') }}</button>
    @endif

    @if ($open)
        <div class="overlay" wire:click.self="close" wire:keydown.escape="close">
            <form class="modal modal--wide" role="dialog" aria-modal="true" aria-labelledby="be-declare-title" wire:submit="save">
                <h2 id="be-declare-title">{{ __('design_office_workflow.declare.title') }}</h2>
                <p class="field__hint">{{ __('design_office_workflow.declare.hint') }}</p>
                @error('form') <div class="field-warning" role="alert">{{ $message }}</div> @enderror

                <div class="field">
                    <label class="field__label" for="be-declare-audit">{{ __('design_office_workflow.occurrences.audit') }} *</label>
                    <select class="field__input" id="be-declare-audit" wire:model.live="auditId">
                        <option value="0">{{ __('design_office_workflow.declare.choose') }}</option>
                        @foreach ($audits as $audit)
                            <option value="{{ $audit->id }}">{{ $audit->cabinet_key }}</option>
                        @endforeach
                    </select>
                    @error('auditId') <p class="field__error">{{ $message }}</p> @enderror
                </div>

                <div class="field">
                    <label class="field__label" for="be-declare-box">{{ __('design_office_workflow.occurrences.box') }} *</label>
                    <select class="field__input" id="be-declare-box" wire:model.live="boxId">
                        <option value="0">{{ __('design_office_workflow.declare.choose') }}</option>
                        @foreach ($boxes as $box)
                            <option value="{{ $box->id }}">{{ $box->name }}</option>
                        @endforeach
                    </select>
                    @error('boxId') <p class="field__error">{{ $message }}</p> @enderror
                </div>

                <div class="field">
                    <label class="field__label" for="be-declare-type">{{ __('design_office_workflow.occurrences.type') }} *</label>
                    <select class="field__input" id="be-declare-type" wire:model="typeId">
                        <option value="">{{ __('design_office_workflow.declare.choose') }}</option>
                        @foreach ($types as $type)
                            <option value="{{ $type->id }}">{{ $type->displayLabel() }} — {{ $type->nature->label() }}</option>
                        @endforeach
                    </select>
                    @error('typeId') <p class="field__error">{{ $message }}</p> @enderror
                </div>

                <div class="field">
                    <label class="field__label" for="be-declare-description">{{ __('design_office_workflow.declare.description') }} *</label>
                    <textarea class="field__input" id="be-declare-description" rows="3" maxlength="2000" wire:model="description"></textarea>
                    @error('description') <p class="field__error">{{ $message }}</p> @enderror
                </div>

                <div class="field">
                    <label class="field__label" for="be-declare-location">{{ __('design_office_workflow.declare.location') }}</label>
                    <input class="field__input" id="be-declare-location" maxlength="500" wire:model="locationNote">
                    @error('locationNote') <p class="field__error">{{ $message }}</p> @enderror
                </div>

                <fieldset class="field">
                    <legend class="field__label">{{ __('design_office_workflow.declare.photos') }} *</legend>
                    @if ($photos->isEmpty())
                        <p class="field__hint">{{ __('design_office_workflow.declare.no_photos') }}</p>
                    @else
                        <div class="be-declare__photos">
                            @foreach ($photos as $photo)
                                <label class="be-declare__photo" wire:key="declare-photo-{{ $photo->id }}">
                                    <input type="checkbox" value="{{ $photo->id }}" wire:model="photoIds">
                                    <img src="{{ route('audits.photos.show', ['photo' => $photo->id, 'variant' => 'thumb']) }}" alt="" loading="lazy">
                                    <small>{{ \App\Enums\AuditFolder::tryFrom($photo->folder)?->label() ?? $photo->folder }}</small>
                                </label>
                            @endforeach
                        </div>
                    @endif
                    @error('photoIds') <p class="field__error">{{ $message }}</p> @enderror
                </fieldset>

                <div class="modal-actions">
                    <button type="button" class="btn btn--ghost" wire:click="close">{{ __('design_office_workflow.closure.cancel') }}</button>
                    <button type="submit" class="btn btn--primary" wire:loading.attr="disabled">{{ __('design_office_workflow.declare.submit') }}</button>
                </div>
            </form>
        </div>
    @endif
</div>
