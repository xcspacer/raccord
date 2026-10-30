<div class="review-queue">
    @php($format = fn (?int $seconds) => \App\DesignOfficeWorkflow\ProductionTime::format($seconds))
    <p class="page-hint">{{ $isSupervisor ? __('design_office_workflow.indicators.hint_supervisor') : __('design_office_workflow.indicators.hint_own') }}</p>

    <div class="review-indicators__period">
        <label class="field">
            <span class="field__label">{{ __('design_office_workflow.indicators.from') }}</span>
            <input class="field__input" type="date" wire:model.live="from">
        </label>
        <label class="field">
            <span class="field__label">{{ __('design_office_workflow.indicators.to') }}</span>
            <input class="field__input" type="date" wire:model.live="to">
        </label>
    </div>

    @if ($isSupervisor || $reviewers !== [])
        <section class="settings-card review-queue__section">
            <h2 class="settings-card__title">{{ __('design_office_workflow.indicators.reviewers') }}</h2>
            @if ($reviewers === [])
                <div class="empty-state">{{ __('design_office_workflow.indicators.no_data') }}</div>
            @else
                <div class="review-indicators__table-wrap">
                    <table class="review-indicators__table">
                        <thead>
                            <tr>
                                <th>{{ __('design_office_workflow.indicators.person') }}</th>
                                <th>{{ __('design_office_workflow.indicators.decisions') }}</th>
                                <th>{{ __('design_office_workflow.indicators.average_decision') }}</th>
                                <th>{{ __('design_office_workflow.indicators.late') }}</th>
                                <th>{{ __('design_office_workflow.indicators.refused') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($reviewers as $row)
                                <tr wire:key="reviewer-{{ $row['id'] }}">
                                    <td>{{ $row['name'] }}</td>
                                    <td>{{ $row['decisions'] }}</td>
                                    <td>{{ $format($row['average']) }}</td>
                                    <td>{{ $row['late'] }}</td>
                                    <td>{{ $row['refused'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif

    @if ($isSupervisor || $technicians !== [] || $boxes !== [])
        <section class="settings-card review-queue__section">
            <h2 class="settings-card__title">{{ __('design_office_workflow.indicators.technicians') }}</h2>
            @if ($technicians === [])
                <div class="empty-state">{{ __('design_office_workflow.indicators.no_data') }}</div>
            @else
                <div class="review-indicators__table-wrap">
                    <table class="review-indicators__table">
                        <thead>
                            <tr>
                                <th>{{ __('design_office_workflow.indicators.person') }}</th>
                                <th>{{ __('design_office_workflow.indicators.submissions') }}</th>
                                <th>{{ __('design_office_workflow.indicators.boxes') }}</th>
                                <th>{{ __('design_office_workflow.indicators.refused') }}</th>
                                <th>{{ __('design_office_workflow.indicators.first_time') }}</th>
                                <th>{{ __('design_office_workflow.indicators.average_production') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($technicians as $row)
                                <tr wire:key="technician-{{ $row['id'] }}">
                                    <td>{{ $row['name'] }}</td>
                                    <td>{{ $row['submissions'] }}</td>
                                    <td>{{ $row['boxes'] }}</td>
                                    <td>{{ $row['refused'] }}</td>
                                    <td>{{ $row['first_time'] }}</td>
                                    <td>{{ $format($row['average']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="settings-card review-queue__section">
            <h2 class="settings-card__title">{{ __('design_office_workflow.indicators.production') }}</h2>
            <p class="page-hint">{{ __('design_office_workflow.indicators.production_hint') }}</p>
            @if ($boxes === [])
                <div class="empty-state">{{ __('design_office_workflow.indicators.no_data') }}</div>
            @else
                <div class="review-indicators__table-wrap">
                    <table class="review-indicators__table">
                        <thead>
                            <tr>
                                <th>{{ __('design_office_workflow.indicators.box') }}</th>
                                <th>{{ __('design_office_workflow.indicators.gross') }}</th>
                                <th>{{ __('design_office_workflow.indicators.waiting') }}</th>
                                <th>{{ __('design_office_workflow.indicators.paused') }}</th>
                                <th>{{ __('design_office_workflow.indicators.computed') }}</th>
                                <th>{{ __('design_office_workflow.indicators.adjusted') }}</th>
                                @if ($isSupervisor)<th></th>@endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($boxes as $row)
                                <tr wire:key="box-{{ $row['box']->id }}">
                                    <td>{{ $row['box']->name }} <small>{{ $row['box']->audit->cabinet_key }}</small></td>
                                    <td>{{ $format($row['time']['gross']) }}</td>
                                    <td>{{ $format($row['time']['waiting']) }}</td>
                                    <td>{{ $format($row['time']['paused']) }}</td>
                                    <td>{{ $format($row['time']['computed']) }}</td>
                                    <td>
                                        {{ $row['time']['adjusted'] === null ? '—' : $format($row['time']['adjusted']) }}
                                        @if ($row['time']['adjustment'])
                                            <small class="review-indicators__note">{{ $row['time']['adjustment']->justification }}</small>
                                        @endif
                                    </td>
                                    @if ($isSupervisor)
                                        <td>
                                            @if ($row['time']['computed'] !== null)
                                                <button type="button" class="btn btn--ghost btn--small" wire:click="startAdjustment({{ $row['box']->id }})">{{ __('design_office_workflow.indicators.adjust') }}</button>
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                                @if ($adjusting === $row['box']->id)
                                    <tr wire:key="adjust-{{ $row['box']->id }}">
                                        <td colspan="7">
                                            <form class="review-claims__form" wire:submit="adjust">
                                                <div class="field">
                                                    <label class="field__label" for="adjust-minutes">{{ __('design_office_workflow.indicators.minutes') }}</label>
                                                    <input class="field__input field__input--narrow" type="number" min="0" id="adjust-minutes" wire:model="minutes">
                                                    @error('minutes') <p class="field__error">{{ $message }}</p> @enderror
                                                </div>
                                                <div class="field">
                                                    <label class="field__label" for="adjust-justification">{{ __('design_office_workflow.indicators.justification') }}</label>
                                                    <textarea class="field__input" id="adjust-justification" rows="2" maxlength="2000" wire:model="justification"></textarea>
                                                    @error('justification') <p class="field__error">{{ $message }}</p> @enderror
                                                </div>
                                                <div class="review-card__actions">
                                                    <button type="submit" class="btn btn--primary btn--small">{{ __('design_office_workflow.indicators.save') }}</button>
                                                    <button type="button" class="btn btn--ghost btn--small" wire:click="startAdjustment({{ $row['box']->id }})">{{ __('design_office_workflow.claims.cancel') }}</button>
                                                </div>
                                            </form>
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif
</div>
