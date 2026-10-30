<div class="review-queue be-closure" wire:poll.30s.visible>
    <nav class="be-closure__tabs" aria-label="{{ __('design_office_workflow.closure.title') }}">
        <a class="btn btn--ghost" href="{{ route('design-office.queue') }}" wire:navigate>{{ __('design_office_workflow.repairs.queue_tab') }}</a>
        @can('viewAny', \App\Models\AuditIntervention::class)
            <a class="btn btn--ghost" href="{{ route('design-office.repairs') }}" wire:navigate>{{ __('design_office_workflow.repairs.tab') }}</a>
        @endcan
        <a class="btn btn--ghost is-active" href="{{ route('design-office.closure') }}" aria-current="page" wire:navigate>{{ __('design_office_workflow.closure.title') }}</a>
    </nav>
    <p class="page-hint">{{ __('design_office_workflow.closure.hint') }}</p>

    @if ($audit === null)
        <div class="empty-state">{{ __('design_office_workflow.closure.no_audits') }}</div>
    @else
        <section class="settings-card be-closure__head">
            <label class="be-closure__pick">
                <span class="field__label">{{ __('design_office_workflow.closure.pm') }}</span>
                <select class="field__input" wire:model.live="cabinet">
                    @foreach ($audits as $option)
                        <option value="{{ $option->cabinet_key }}">{{ $option->cabinet_key }}@if ($option->closed_at) 🔒@endif</option>
                    @endforeach
                </select>
            </label>
            <div class="be-closure__chips">
                <span @class(['be-closure__chip', 'be-closure__chip--ok' => $audit->isClosed()])>{{ $audit->isClosed() ? __('design_office_workflow.closure.closed') : $audit->phase->label() }}</span>
                <span class="be-closure__chip">{{ __('design_office_workflow.closure.summary.boxes') }} : {{ $report['summary']['boxes'] }}</span>
                <span class="be-closure__chip be-closure__chip--ok">{{ __('design_office_workflow.closure.summary.conforming') }} : {{ $report['summary']['conforming'] }}</span>
                <span class="be-closure__chip be-closure__chip--warn">{{ __('design_office_workflow.closure.summary.with_reserve') }} : {{ $report['summary']['with_reserve'] }}</span>
                <span class="be-closure__chip">{{ __('design_office_workflow.closure.summary.occurrences') }} : {{ $report['summary']['occurrences'] }}</span>
            </div>
            @if ($audit->isClosed())
                <p class="be-closure__closed">🔒 {{ __('design_office_workflow.closure.closed_by', ['name' => $audit->closer?->name ?? '—', 'date' => $audit->closed_at->timezone(config('platform.timezone'))->format('d/m/Y H:i')]) }}</p>
            @endif
        </section>

        @if (! $audit->isClosed())
            <section class="settings-card">
                <h2 class="settings-card__title">{{ __('design_office_workflow.closure.checks_title') }}</h2>
                @if ($report['blockers'] === [])
                    <p class="be-closure__all-ok">✓ {{ __('design_office_workflow.closure.checks_ok') }}</p>
                @endif
                @if ($report['blockers'] !== [] || $report['warnings'] !== [])
                    <ul class="be-closure__checks">
                        @foreach ([...array_map(fn ($check) => [...$check, 'level' => 'blocker'], $report['blockers']), ...array_map(fn ($check) => [...$check, 'level' => 'warning'], $report['warnings'])] as $check)
                            <li class="be-closure__check be-closure__check--{{ $check['level'] }}" wire:key="check-{{ $check['code'] }}">
                                <strong>{{ __('design_office_workflow.closure.checks.'.$check['code']) }}</strong>
                                @if ($check['items'] !== [])
                                    <small class="be-closure__items">{{ implode(' · ', array_slice($check['items'], 0, 12)) }}@if (count($check['items']) > 12) {{ __('design_office_workflow.closure.more_items', ['count' => count($check['items']) - 12]) }}@endif</small>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if ($isSupervisor)
                    <div class="form-actions form-actions--left">
                        <button type="button" class="btn btn--primary" wire:click="openDialog('close')" @disabled(! $report['ok'])>🔒 {{ __('design_office_workflow.closure.close_button') }}</button>
                    </div>
                    <p class="field__hint">{{ __('design_office_workflow.closure.close_hint') }}</p>
                @else
                    <p class="field__hint">{{ __('design_office_workflow.closure.close_supervisor_only') }}</p>
                @endif
            </section>
        @endif

        @if ($audit->isClosed() && $isSupervisor)
            <section class="settings-card">
                <h2 class="settings-card__title">{{ __('design_office_workflow.closure.reopen_section') }}</h2>
                <p class="field__hint">{{ __('design_office_workflow.closure.reopen_hint') }}</p>
                <div class="form-actions form-actions--left">
                    <button type="button" class="btn btn--ghost" wire:click="openDialog('reopen')">↺ {{ __('design_office_workflow.closure.reopen_button') }}</button>
                </div>
            </section>
        @endif

        @if ($reopenings->isNotEmpty())
            <section class="settings-card">
                <h2 class="settings-card__title">{{ __('design_office_workflow.closure.history') }}</h2>
                <div class="review-list">
                    @foreach ($reopenings as $reopening)
                        <article class="review-card" wire:key="reopening-{{ $reopening->id }}">
                            <div class="review-card__main">
                                <div class="review-card__meta">{{ $reopening->reopened_at->timezone(config('platform.timezone'))->format('d/m/Y H:i') }} · {{ $reopening->reopener->name }}</div>
                                <p class="review-card__text">{{ $reopening->reason }}</p>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        @includeIf('livewire.design-office-workflow.partials.closure-documents', ['audit' => $audit])

        <section class="settings-card">
            <h2 class="settings-card__title">{{ __('design_office_workflow.closure.open_title') }}</h2>
            @if ($open->isEmpty())
                <div class="empty-state">{{ __('design_office_workflow.closure.no_open') }}</div>
            @else
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>{{ __('design_office_workflow.closure.columns.reference') }}</th>
                            <th>{{ __('design_office_workflow.closure.columns.box') }}</th>
                            <th>{{ __('design_office_workflow.closure.columns.type') }}</th>
                            <th>{{ __('design_office_workflow.closure.columns.description') }}</th>
                            <th>{{ __('design_office_workflow.closure.columns.proposal') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($open as $occurrence)
                            @php($proposal = $occurrence->reserveProposals->first())
                            <tr wire:key="open-{{ $occurrence->id }}">
                                <td data-label="{{ __('design_office_workflow.closure.columns.reference') }}"><strong>{{ $occurrence->reference }}</strong></td>
                                <td data-label="{{ __('design_office_workflow.closure.columns.box') }}" class="be-closure__mono">{{ $occurrence->box->name }}</td>
                                <td data-label="{{ __('design_office_workflow.closure.columns.type') }}">{{ $occurrence->type->displayLabel() }} <span class="review-nature review-nature--{{ $occurrence->nature->value }}">{{ $occurrence->nature->label() }}</span></td>
                                <td data-label="{{ __('design_office_workflow.closure.columns.description') }}">{{ $occurrence->description }}</td>
                                <td data-label="{{ __('design_office_workflow.closure.columns.proposal') }}">
                                    @if ($proposal)
                                        <small>{{ $proposal->proposer->name }} : {{ $proposal->reason }}</small>
                                        @if ($proposal->state === \App\Enums\AuditReserveProposalState::Rejected)
                                            <small class="be-closure__items">{{ __('design_office_workflow.closure.proposal_refused', ['who' => $proposal->decider?->name ?? '—', 'reason' => $proposal->decision_reason]) }}</small>
                                        @endif
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="be-closure__actions">
                                    @unless ($audit->isClosed())
                                        @if ($isSupervisor)
                                            <button type="button" class="btn btn--primary btn--small" wire:click="openDialog('accept', {{ $occurrence->id }})">{{ __('design_office_workflow.closure.accept_button') }}</button>
                                            @if ($proposal?->state === \App\Enums\AuditReserveProposalState::Open)
                                                <button type="button" class="btn btn--ghost btn--small" wire:click="openDialog('refuse', {{ $occurrence->id }})">{{ __('design_office_workflow.closure.refuse_button') }}</button>
                                            @endif
                                        @elseif ($canPropose)
                                            <button type="button" class="btn btn--ghost btn--small" wire:click="openDialog('propose', {{ $occurrence->id }})">{{ __('design_office_workflow.closure.propose_button') }}</button>
                                        @endif
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        <section class="settings-card">
            <h2 class="settings-card__title">{{ __('design_office_workflow.closure.accepted_title') }}</h2>
            @if ($accepted->isEmpty())
                <div class="empty-state">{{ __('design_office_workflow.closure.no_accepted') }}</div>
            @else
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>{{ __('design_office_workflow.closure.columns.reference') }}</th>
                            <th>{{ __('design_office_workflow.closure.columns.box') }}</th>
                            <th>{{ __('design_office_workflow.closure.columns.kind') }}</th>
                            <th>{{ __('design_office_workflow.closure.columns.reason') }}</th>
                            <th>{{ __('design_office_workflow.closure.columns.accepted_by') }}</th>
                            <th>{{ __('design_office_workflow.closure.columns.status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($accepted as $reserve)
                            <tr wire:key="reserve-{{ $reserve->id }}">
                                <td data-label="{{ __('design_office_workflow.closure.columns.reference') }}"><strong>{{ $reserve->occurrence?->reference ?? '—' }}</strong></td>
                                <td data-label="{{ __('design_office_workflow.closure.columns.box') }}" class="be-closure__mono">{{ $reserve->box->name }}</td>
                                <td data-label="{{ __('design_office_workflow.closure.columns.kind') }}">{{ $reserve->kind->label() }}</td>
                                <td data-label="{{ __('design_office_workflow.closure.columns.reason') }}">{{ $reserve->reason }}</td>
                                <td data-label="{{ __('design_office_workflow.closure.columns.accepted_by') }}">{{ $reserve->accepter->name }} <small>{{ $reserve->accepted_at->timezone(config('platform.timezone'))->format('d/m/Y H:i') }}</small></td>
                                <td data-label="{{ __('design_office_workflow.closure.columns.status') }}" class="be-closure__actions">
                                    @if ($reserve->isOpen())
                                        <span class="be-closure__chip be-closure__chip--warn">{{ __('design_office_workflow.closure.reserve_open') }}</span>
                                        @if ($isSupervisor)
                                            <button type="button" class="btn btn--ghost btn--small" wire:click="openDialog('close_reserve', {{ $reserve->id }})">{{ __('design_office_workflow.closure.close_reserve_button') }}</button>
                                        @endif
                                    @else
                                        <span class="be-closure__chip be-closure__chip--ok">{{ __('design_office_workflow.closure.reserve_closed', ['date' => $reserve->closed_at->timezone(config('platform.timezone'))->format('d/m/Y')]) }}</span>
                                        @if ($reserve->closing_reason)<small class="be-closure__items">{{ $reserve->closing_reason }}</small>@endif
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        @includeIf('livewire.design-office-workflow.partials.closure-dossier', ['audit' => $audit])

        @if ($dialog !== '')
            <div class="overlay" wire:keydown.escape="cancel">
                <form class="modal modal--wide" role="dialog" aria-modal="true" aria-labelledby="be-closure-dialog" wire:submit="{{ ['propose' => 'propose', 'accept' => 'accept', 'refuse' => 'refuse', 'close_reserve' => 'closeReserve', 'close' => 'closeAudit', 'reopen' => 'reopen'][$dialog] }}">
                    @php($subject = in_array($dialog, ['propose', 'accept', 'refuse'], true) ? $open->firstWhere('id', $targetId)?->reference : ($dialog === 'close_reserve' ? $accepted->firstWhere('id', $targetId)?->occurrence?->reference : $audit->cabinet_key))
                    <h2 id="be-closure-dialog">{{ __('design_office_workflow.closure.dialogs.'.$dialog.'.title', ['subject' => $subject ?? '—']) }}</h2>

                    @error('dialog') <div class="field-warning" role="alert">{{ $message }}</div> @enderror

                    @if (in_array($dialog, ['accept', 'refuse', 'close', 'reopen'], true))
                        <p class="field__hint">{{ __('design_office_workflow.closure.dialogs.'.$dialog.'.hint') }}</p>
                    @endif

                    @if ($dialog === 'accept')
                        <fieldset class="field review-decisions">
                            <legend class="field__label">{{ __('design_office_workflow.closure.accept_kind') }}</legend>
                            @foreach ($kinds as $option)
                                <label class="review-decisions__option" wire:key="kind-{{ $option->value }}">
                                    <input type="radio" value="{{ $option->value }}" wire:model="kind">
                                    <span>{{ __('design_office_workflow.closure.kind_hints.'.$option->value) }}</span>
                                </label>
                            @endforeach
                        </fieldset>
                    @endif

                    @if ($dialog === 'reopen')
                        <fieldset class="field be-closure__boxes">
                            <legend class="field__label">{{ __('design_office_workflow.closure.reopen_boxes') }}</legend>
                            @foreach ($boxes as $box)
                                <label class="be-closure__box" wire:key="reopen-box-{{ $box->id }}">
                                    <input type="checkbox" value="{{ $box->id }}" wire:model="reopenBoxes">
                                    <span class="be-closure__mono">{{ $box->name }}</span>
                                </label>
                            @endforeach
                        </fieldset>
                    @endif

                    @if ($dialog === 'close')
                        <label class="be-closure__confirm">
                            <input type="checkbox" wire:model="confirmed">
                            <span>{{ __('design_office_workflow.closure.dialogs.close.confirm') }}</span>
                        </label>
                    @else
                        <div class="field">
                            <label class="field__label" for="be-closure-reason">{{ __('design_office_workflow.closure.dialogs.'.$dialog.'.reason') }} *</label>
                            <textarea class="field__input" id="be-closure-reason" rows="3" wire:model="reason" maxlength="2000" autofocus></textarea>
                            @error('reason') <p class="field__error">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    <div class="modal-actions">
                        <button type="button" class="btn btn--ghost" wire:click="cancel">{{ __('design_office_workflow.closure.cancel') }}</button>
                        <button type="submit" @class(['btn', 'btn--primary', 'btn--danger' => $dialog === 'close']) wire:loading.attr="disabled">{{ __('design_office_workflow.closure.dialogs.'.$dialog.'.submit') }}</button>
                    </div>
                </form>
            </div>
        @endif
    @endif
</div>
