<div class="review-queue">
    <p class="page-hint">{{ __('design_office_workflow.catalogues.hint') }}</p>

    <section class="settings-card">
        <h2 class="settings-card__title">{{ __('design_office_workflow.catalogues.anomaly_types') }}</h2>
        @error('anomaly') <div class="field-warning" role="alert">{{ $message }}</div> @enderror

        <table class="data-table">
            <thead>
                <tr>
                    <th>{{ __('design_office_workflow.catalogues.label') }}</th>
                    <th>{{ __('design_office_workflow.catalogues.nature') }}</th>
                    <th>{{ __('design_office_workflow.catalogues.active') }}</th>
                    <th>{{ __('design_office_workflow.catalogues.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($anomalyTypes as $type)
                    <tr wire:key="anomaly-{{ $type->id }}" @class(['be-catalogue__row--off' => ! $type->is_active])>
                        <td data-label="{{ __('design_office_workflow.catalogues.label') }}">
                            @if ($editingAnomalyId === $type->id)
                                <form class="be-catalogue__inline" wire:submit="renameAnomalyType">
                                    <input class="field__input" type="text" maxlength="160" wire:model="editingAnomalyLabel" placeholder="{{ $type->code ? __('design_office_workflow.anomaly_types.'.$type->code) : '' }}" aria-label="{{ __('design_office_workflow.catalogues.label') }}">
                                    <button type="submit" class="btn btn--primary btn--small">{{ __('design_office_workflow.catalogues.save') }}</button>
                                    <button type="button" class="btn btn--ghost btn--small" wire:click="cancelAnomalyEdit">{{ __('design_office_workflow.catalogues.cancel') }}</button>
                                </form>
                                @error('editingAnomalyLabel') <p class="field__error">{{ $message }}</p> @enderror
                            @else
                                {{ $type->displayLabel() }}
                                @if ($type->code)
                                    <span class="be-catalogue__tag">{{ __('design_office_workflow.catalogues.built_in') }}</span>
                                @endif
                            @endif
                        </td>
                        <td data-label="{{ __('design_office_workflow.catalogues.nature') }}"><span class="review-nature review-nature--{{ $type->nature->value }}">{{ $type->nature->label() }}</span></td>
                        <td data-label="{{ __('design_office_workflow.catalogues.active') }}">
                            <label class="toggle-switch">
                                <input type="checkbox" @checked($type->is_active) wire:click="toggleAnomalyType({{ $type->id }})" aria-label="{{ __('design_office_workflow.catalogues.active_for', ['label' => $type->displayLabel()]) }}">
                                <span></span>
                            </label>
                        </td>
                        <td data-label="{{ __('design_office_workflow.catalogues.actions') }}">
                            <button type="button" class="btn btn--ghost btn--small" wire:click="editAnomalyType({{ $type->id }})">{{ __('design_office_workflow.catalogues.rename') }}</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <form class="be-catalogue__add" wire:submit="addAnomalyType">
            <h3 class="review-folder__title">{{ __('design_office_workflow.catalogues.new_anomaly_type') }}</h3>
            <div class="be-catalogue__fields">
                <div class="field">
                    <label class="field__label" for="anomaly-label">{{ __('design_office_workflow.catalogues.label') }}</label>
                    <input class="field__input" id="anomaly-label" type="text" maxlength="160" wire:model="anomalyLabel">
                    @error('anomalyLabel') <p class="field__error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label class="field__label" for="anomaly-nature">{{ __('design_office_workflow.catalogues.nature') }}</label>
                    <select class="field__input" id="anomaly-nature" wire:model="anomalyNature">
                        <option value="">—</option>
                        @foreach ($natures as $nature)
                            <option value="{{ $nature->value }}">{{ $nature->label() }}</option>
                        @endforeach
                    </select>
                    @error('anomalyNature') <p class="field__error">{{ $message }}</p> @enderror
                </div>
            </div>
            <p class="field__hint">{{ __('design_office_workflow.catalogues.nature_fixed') }}</p>
            <div class="form-actions">
                <button type="submit" class="btn btn--primary">{{ __('design_office_workflow.catalogues.add') }}</button>
            </div>
        </form>
    </section>

    <section class="settings-card">
        <h2 class="settings-card__title">{{ __('design_office_workflow.catalogues.decision_types') }}</h2>
        @error('decision') <div class="field-warning" role="alert">{{ $message }}</div> @enderror

        <table class="data-table">
            <thead>
                <tr>
                    <th>{{ __('design_office_workflow.catalogues.label') }}</th>
                    <th>{{ __('design_office_workflow.catalogues.kind') }}</th>
                    <th>{{ __('design_office_workflow.catalogues.outcome') }}</th>
                    <th>{{ __('design_office_workflow.catalogues.requirements') }}</th>
                    <th>{{ __('design_office_workflow.catalogues.active') }}</th>
                    <th>{{ __('design_office_workflow.catalogues.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($decisionTypes as $type)
                    <tr wire:key="decision-{{ $type->id }}" @class(['be-catalogue__row--off' => ! $type->is_active])>
                        <td data-label="{{ __('design_office_workflow.catalogues.label') }}">
                            @if ($editingDecisionId === $type->id)
                                <form class="be-catalogue__inline" wire:submit="updateDecisionType">
                                    <input class="field__input" type="text" maxlength="120" wire:model="editingDecisionLabel" aria-label="{{ __('design_office_workflow.catalogues.label') }}">
                                    <label class="be-catalogue__check"><input type="checkbox" wire:model="editingDecisionRequiresReason"> {{ __('design_office_workflow.catalogues.requires_reason') }}</label>
                                    <label class="be-catalogue__check"><input type="checkbox" wire:model="editingDecisionRequiresTargets"> {{ __('design_office_workflow.catalogues.requires_targets') }}</label>
                                    <button type="submit" class="btn btn--primary btn--small">{{ __('design_office_workflow.catalogues.save') }}</button>
                                    <button type="button" class="btn btn--ghost btn--small" wire:click="cancelDecisionEdit">{{ __('design_office_workflow.catalogues.cancel') }}</button>
                                </form>
                                @error('editingDecisionLabel') <p class="field__error">{{ $message }}</p> @enderror
                            @else
                                <span class="review-outcome review-outcome--{{ $type->outcome->value }}">{{ $type->displayLabel() }}</span>
                                @if ($builtIn($type))
                                    <span class="be-catalogue__tag">{{ __('design_office_workflow.catalogues.built_in') }}</span>
                                @endif
                            @endif
                        </td>
                        <td data-label="{{ __('design_office_workflow.catalogues.kind') }}">{{ $type->submission_kind->label() }}</td>
                        <td data-label="{{ __('design_office_workflow.catalogues.outcome') }}">{{ $type->outcome->label() }}</td>
                        <td data-label="{{ __('design_office_workflow.catalogues.requirements') }}">
                            @if ($type->requires_reason)
                                <span class="be-catalogue__tag">{{ __('design_office_workflow.catalogues.reason') }}</span>
                            @endif
                            @if ($type->requires_targets)
                                <span class="be-catalogue__tag">{{ __('design_office_workflow.catalogues.targets') }}</span>
                            @endif
                        </td>
                        <td data-label="{{ __('design_office_workflow.catalogues.active') }}">
                            <label class="toggle-switch">
                                <input type="checkbox" @checked($type->is_active) wire:click="toggleDecisionType({{ $type->id }})" aria-label="{{ __('design_office_workflow.catalogues.active_for', ['label' => $type->displayLabel()]) }}">
                                <span></span>
                            </label>
                        </td>
                        <td data-label="{{ __('design_office_workflow.catalogues.actions') }}">
                            <button type="button" class="btn btn--ghost btn--small" wire:click="editDecisionType({{ $type->id }})">{{ __('design_office_workflow.catalogues.edit') }}</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <form class="be-catalogue__add" wire:submit="addDecisionType">
            <h3 class="review-folder__title">{{ __('design_office_workflow.catalogues.new_decision_type') }}</h3>
            <div class="be-catalogue__fields">
                <div class="field">
                    <label class="field__label" for="decision-label">{{ __('design_office_workflow.catalogues.label') }}</label>
                    <input class="field__input" id="decision-label" type="text" maxlength="120" wire:model="decisionLabel">
                    @error('decisionLabel') <p class="field__error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label class="field__label" for="decision-outcome">{{ __('design_office_workflow.catalogues.outcome') }}</label>
                    <select class="field__input" id="decision-outcome" wire:model="decisionOutcome">
                        <option value="">—</option>
                        @foreach ($kinds as $kind)
                            <optgroup label="{{ $kind->label() }}">
                                @foreach ($outcomes as $outcome)
                                    @if ($outcome->submissionKind() === $kind)
                                        <option value="{{ $outcome->value }}">{{ $outcome->label() }}</option>
                                    @endif
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    @error('decisionOutcome') <p class="field__error">{{ $message }}</p> @enderror
                </div>
            </div>
            <label class="be-catalogue__check"><input type="checkbox" wire:model="decisionRequiresReason"> {{ __('design_office_workflow.catalogues.requires_reason') }}</label>
            <label class="be-catalogue__check"><input type="checkbox" wire:model="decisionRequiresTargets"> {{ __('design_office_workflow.catalogues.requires_targets') }}</label>
            <p class="field__hint">{{ __('design_office_workflow.catalogues.outcome_fixed') }}</p>
            <div class="form-actions">
                <button type="submit" class="btn btn--primary">{{ __('design_office_workflow.catalogues.add') }}</button>
            </div>
        </form>
    </section>
</div>
