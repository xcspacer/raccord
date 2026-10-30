<div class="repairs-page">
    <nav class="repairs-page__tabs" aria-label="{{ __('design_office_workflow.repairs.title') }}">
        <a class="btn btn--ghost" href="{{ route('design-office.queue') }}" wire:navigate>{{ __('design_office_workflow.repairs.queue_tab') }}</a>
        <a class="btn btn--ghost is-active" href="{{ route('design-office.repairs') }}" aria-current="page" wire:navigate>{{ __('design_office_workflow.repairs.tab') }}</a>
        @can('viewClosure', \App\Models\Audit::class)
            <a class="btn btn--ghost" href="{{ route('design-office.closure') }}" wire:navigate>{{ __('design_office_workflow.closure.title') }}</a>
        @endcan
    </nav>

    <p class="page-hint">{{ __('design_office_workflow.repairs.hint') }}</p>

    @if (! $planningBox && ! $cancelling)
        @error('action') <div class="field-warning" role="alert">{{ $message }}</div> @enderror
    @endif

    @if ($audits->isEmpty())
        <div class="empty-state">{{ __('design_office_workflow.repairs.no_audit') }}</div>
    @else
        <section class="settings-card repairs-page__head">
            <label class="field repairs-page__audit">
                <span class="field__label">{{ __('design_office_workflow.repairs.audit') }}</span>
                <select class="field__input" wire:model.live="auditId">
                    @foreach ($audits as $option)
                        <option value="{{ $option->id }}">{{ $option->cabinet_key }}</option>
                    @endforeach
                </select>
            </label>

            @if ($audit)
                <div class="repairs-page__chips">
                    <span @class(['repairs-chip', 'repairs-chip--ok' => $audit->phase !== \App\Enums\AuditWorkflowPhase::Inspection])>{{ $audit->phase->label() }}</span>
                    <span class="repairs-chip">{{ __('design_office_workflow.repairs.box_count', ['count' => $boxCount]) }}</span>
                    <span class="repairs-chip repairs-chip--warn">{{ __('design_office_workflow.repairs.to_repair_count', ['count' => $pool->count()]) }}</span>
                </div>
                @if ($audit->phase === \App\Enums\AuditWorkflowPhase::Inspection && ! $audit->isClosed())
                    <button type="button" class="btn btn--primary" wire:click="openPhase" wire:confirm="{{ __('design_office_workflow.repairs.confirm_open', ['cabinet' => $audit->cabinet_key]) }}">{{ __('design_office_workflow.repairs.open_phase') }}</button>
                @endif
            @endif
        </section>

        @if ($audit)
            <section class="settings-card">
                <h2 class="settings-card__title">{{ __('design_office_workflow.repairs.pool') }}</h2>
                @if ($pool->isEmpty())
                    <div class="empty-state">{{ __('design_office_workflow.repairs.no_pool') }}</div>
                @else
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>{{ __('design_office_workflow.repairs.box') }}</th>
                                <th>{{ __('design_office_workflow.repairs.repairs') }}</th>
                                <th>{{ __('design_office_workflow.repairs.references') }}</th>
                                <th>{{ __('design_office_workflow.repairs.intervention') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($pool as $box)
                                @php($open = $box->getAttribute('open_intervention'))
                                <tr wire:key="pool-{{ $box->id }}">
                                    <td data-label="{{ __('design_office_workflow.repairs.box') }}"><strong>{{ $box->name }}</strong></td>
                                    <td data-label="{{ __('design_office_workflow.repairs.repairs') }}">
                                        {{ $box->getAttribute('repairs')->count() }}
                                        @if ($box->getAttribute('repairs')->where('nature', \App\Enums\AuditAnomalyNature::Optical)->isNotEmpty())
                                            <small class="repairs-page__muted">({{ __('design_office_workflow.repairs.optical_count', ['count' => $box->getAttribute('repairs')->where('nature', \App\Enums\AuditAnomalyNature::Optical)->count()]) }})</small>
                                        @endif
                                    </td>
                                    <td data-label="{{ __('design_office_workflow.repairs.references') }}"><small>{{ $box->getAttribute('repairs')->pluck('reference')->implode(', ') }}</small></td>
                                    <td data-label="{{ __('design_office_workflow.repairs.intervention') }}">
                                        @if ($open)
                                            <span class="repairs-chip repairs-chip--info">{{ $open->planned_on->format('d/m/Y') }} · {{ $open->responsible->name }} · {{ $open->status->label() }}</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td>
                                        @if (! $open && $audit->phase !== \App\Enums\AuditWorkflowPhase::Inspection)
                                            <button type="button" class="btn btn--primary btn--small" wire:click="startPlanning({{ $box->id }})">{{ __('design_office_workflow.repairs.plan') }}</button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </section>

            <section class="settings-card">
                <h2 class="settings-card__title">{{ __('design_office_workflow.repairs.interventions') }}</h2>
                @if ($interventions->isEmpty())
                    <div class="empty-state">{{ __('design_office_workflow.repairs.no_interventions') }}</div>
                @else
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>{{ __('design_office_workflow.repairs.date') }}</th>
                                <th>{{ __('design_office_workflow.repairs.box') }}</th>
                                <th>{{ __('design_office_workflow.repairs.responsible') }}</th>
                                <th>{{ __('design_office_workflow.repairs.team') }}</th>
                                <th>{{ __('design_office_workflow.repairs.status') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($interventions as $intervention)
                                <tr wire:key="intervention-{{ $intervention->id }}">
                                    <td data-label="{{ __('design_office_workflow.repairs.date') }}">{{ $intervention->planned_on->format('d/m/Y') }}</td>
                                    <td data-label="{{ __('design_office_workflow.repairs.box') }}"><strong>{{ $intervention->box->name }}</strong></td>
                                    <td data-label="{{ __('design_office_workflow.repairs.responsible') }}">{{ $intervention->responsible->name }}</td>
                                    <td data-label="{{ __('design_office_workflow.repairs.team') }}">{{ $intervention->members->pluck('name')->sort()->implode(', ') }}</td>
                                    <td data-label="{{ __('design_office_workflow.repairs.status') }}">
                                        <span class="repairs-status repairs-status--{{ $intervention->status->value }}">{{ $intervention->status->label() }}</span>
                                        @if ($intervention->cancellation_reason)
                                            <small class="repairs-page__muted">{{ __('design_office_workflow.repairs.cancelled_because', ['reason' => $intervention->cancellation_reason]) }}</small>
                                        @endif
                                        @if ($intervention->note)
                                            <small class="repairs-page__muted">{{ $intervention->note }}</small>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($intervention->status === \App\Enums\AuditInterventionStatus::Planned)
                                            <button type="button" class="btn btn--ghost btn--danger btn--small" wire:click="askCancel({{ $intervention->id }})">{{ __('design_office_workflow.repairs.cancel') }}</button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </section>
        @endif
    @endif

    @if ($planningBox)
        <div class="overlay" wire:click.self="stopPlanning" wire:keydown.escape="stopPlanning">
            <form class="modal modal--wide" role="dialog" aria-modal="true" aria-labelledby="repairs-plan-title" wire:submit="plan">
                <h2 id="repairs-plan-title">{{ __('design_office_workflow.repairs.plan_title', ['box' => $planningBox->name]) }}</h2>
                @error('action') <div class="field-warning" role="alert">{{ $message }}</div> @enderror

                <div class="field">
                    <label class="field__label" for="repairs-date">{{ __('design_office_workflow.repairs.date') }}</label>
                    <input class="field__input" id="repairs-date" type="date" wire:model="plannedOn" required>
                    @error('plannedOn') <p class="field__error">{{ $message }}</p> @enderror
                </div>

                @if ($technicians->isEmpty())
                    <div class="empty-state">{{ __('design_office_workflow.repairs.no_technicians') }}</div>
                @else
                    <div class="field">
                        <label class="field__label" for="repairs-responsible">{{ __('design_office_workflow.repairs.responsible') }}</label>
                        <select class="field__input" id="repairs-responsible" wire:model="responsibleId" required>
                            <option value="">{{ __('design_office_workflow.repairs.choose') }}</option>
                            @foreach ($technicians as $technician)
                                <option value="{{ $technician->id }}">{{ $technician->name }}</option>
                            @endforeach
                        </select>
                        @error('responsibleId') <p class="field__error">{{ $message }}</p> @enderror
                    </div>

                    <fieldset class="field repairs-page__members">
                        <legend class="field__label">{{ __('design_office_workflow.repairs.members') }}</legend>
                        @foreach ($technicians as $technician)
                            <label class="repairs-page__member" wire:key="member-{{ $technician->id }}">
                                <input type="checkbox" value="{{ $technician->id }}" wire:model="memberIds"> {{ $technician->name }}
                            </label>
                        @endforeach
                        @error('memberIds') <p class="field__error">{{ $message }}</p> @enderror
                    </fieldset>
                @endif

                <div class="field">
                    <label class="field__label" for="repairs-note">{{ __('design_office_workflow.repairs.note') }}</label>
                    <input class="field__input" id="repairs-note" wire:model="note" maxlength="2000">
                    @error('note') <p class="field__error">{{ $message }}</p> @enderror
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn--ghost" wire:click="stopPlanning">{{ __('design_office_workflow.repairs.close') }}</button>
                    <button type="submit" class="btn btn--primary" wire:loading.attr="disabled" @disabled($technicians->isEmpty())>{{ __('design_office_workflow.repairs.plan') }}</button>
                </div>
            </form>
        </div>
    @endif

    @if ($cancelling)
        <div class="overlay" wire:click.self="closeCancel" wire:keydown.escape="closeCancel">
            <form class="modal" role="dialog" aria-modal="true" aria-labelledby="repairs-cancel-title" wire:submit="cancel">
                <h2 id="repairs-cancel-title">{{ __('design_office_workflow.repairs.cancel_title', ['box' => $cancelling->box->name]) }}</h2>
                @error('action') <div class="field-warning" role="alert">{{ $message }}</div> @enderror

                <div class="field">
                    <label class="field__label" for="repairs-cancel-reason">{{ __('design_office_workflow.repairs.cancellation_reason') }}</label>
                    <textarea class="field__input" id="repairs-cancel-reason" rows="3" wire:model="cancellationReason" maxlength="2000" required></textarea>
                    @error('cancellationReason') <p class="field__error">{{ $message }}</p> @enderror
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn--ghost" wire:click="closeCancel">{{ __('design_office_workflow.repairs.close') }}</button>
                    <button type="submit" class="btn btn--primary btn--danger">{{ __('design_office_workflow.repairs.cancel') }}</button>
                </div>
            </form>
        </div>
    @endif
</div>
