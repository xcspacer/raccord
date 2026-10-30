<div class="review-queue" wire:poll.10s.visible>
    <nav class="repairs-page__tabs" aria-label="{{ __('design_office_workflow.repairs.title') }}">
        <a class="btn btn--ghost is-active" href="{{ route('design-office.queue') }}" aria-current="page" wire:navigate>{{ __('design_office_workflow.repairs.queue_tab') }}</a>
        @can('viewAny', \App\Models\AuditIntervention::class)
            <a class="btn btn--ghost" href="{{ route('design-office.repairs') }}" wire:navigate>{{ __('design_office_workflow.repairs.tab') }}</a>
        @endcan
        @can('viewClosure', \App\Models\Audit::class)
            <a class="btn btn--ghost" href="{{ route('design-office.closure') }}" wire:navigate>{{ __('design_office_workflow.closure.title') }}</a>
        @endcan
    </nav>
    <p class="page-hint">{{ $isSupervisor ? __('design_office_workflow.queue.hint_supervisor') : __('design_office_workflow.queue.hint_reviewer') }}</p>

    @error('action') <div class="field-warning" role="alert">{{ $message }}</div> @enderror

    @if ($isReviewer)
        <div class="module-row review-queue__availability">
            <span class="module-row__text">
                <span class="module-row__label">{{ __('design_office_workflow.queue.my_availability') }}</span>
                <small>{{ $user->is_available_for_review ? __('design_office_workflow.queue.available_hint') : __('design_office_workflow.queue.unavailable_hint') }}</small>
            </span>
            <label class="toggle-switch">
                <input type="checkbox" @checked($user->is_available_for_review) wire:click="toggleAvailability" aria-label="{{ __('design_office_workflow.queue.my_availability') }}">
                <span></span>
            </label>
        </div>
    @endif

    <section class="settings-card review-queue__section">
        <h2 class="settings-card__title">{{ $isSupervisor ? __('design_office_workflow.queue.open_requests') : __('design_office_workflow.queue.my_requests') }}</h2>

        @if ($requests->isEmpty())
            <div class="empty-state">{{ __('design_office_workflow.queue.empty') }}</div>
        @else
            <div class="review-list">
                @foreach ($requests as $request)
                    <article wire:key="request-{{ $request->id }}" @class([
                        'review-card',
                        'review-card--alert' => $request->alerted_at !== null && ! $request->isLate(),
                        'review-card--late' => $request->isLate(),
                        'review-card--central' => $request->assigned_to === null,
                    ])>
                        <div class="review-card__main">
                            <div class="review-card__title">
                                <span class="review-card__box">{{ $request->box->name }}</span>
                                <span class="review-card__cabinet">{{ $request->box->audit->cabinet_key }}</span>
                                @if ($request->submission->kind === \App\Enums\AuditSubmissionKind::Repair)
                                    <span class="repairs-chip repairs-chip--info">{{ __('design_office_workflow.repairs.kind') }}</span>
                                @endif
                            </div>
                            <div class="review-card__meta">
                                {{ __('design_office_workflow.queue.submitted_by', ['name' => $request->submission->submitter->name, 'time' => $request->queued_at->timezone(config('platform.timezone'))->format('H:i')]) }}
                                · <span class="review-state review-state--{{ $request->state->value }}">{{ $request->state->label() }}</span>
                                @if ($isSupervisor)
                                    · {{ $request->reviewer?->name ?? __('design_office_workflow.queue.central_queue') }}
                                @endif
                            </div>
                        </div>

                        <span class="review-countdown" x-data="reviewCountdown" data-deadline="{{ $request->deadline_at->toIso8601String() }}" data-late-label="{{ __('design_office_workflow.queue.late') }}" x-text="text" x-bind:class="{ 'review-countdown--late': late }">{{ $request->isLate() ? __('design_office_workflow.queue.late') : '' }}</span>

                        <div class="review-card__actions">
                            @if ($isSupervisor)
                                <select class="field__input review-card__select" aria-label="{{ __('design_office_workflow.queue.reassign') }}" wire:change="reassign({{ $request->id }}, $event.target.value)">
                                    <option value="">{{ __('design_office_workflow.queue.reassign') }}</option>
                                    @foreach ($reviewers as $reviewer)
                                        @if ($reviewer->id !== $request->assigned_to)
                                            <option value="{{ $reviewer->id }}">{{ $reviewer->name }}</option>
                                        @endif
                                    @endforeach
                                </select>
                            @endif
                            <a class="btn btn--primary btn--small" href="{{ route('design-office.requests.show', $request) }}" wire:navigate>{{ __('design_office_workflow.queue.open') }}</a>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    @if ($controls->isNotEmpty())
        <section class="settings-card review-queue__section">
            <h2 class="settings-card__title">{{ __('design_office_workflow.queue.controls') }}</h2>
            <div class="review-list">
                @foreach ($controls as $control)
                    <article class="review-card" wire:key="control-{{ $control->id }}">
                        <div class="review-card__main">
                            <div class="review-card__title">
                                <span class="review-card__box">{{ $control->box->name }}</span>
                                <span class="review-card__cabinet">{{ $control->box->audit->cabinet_key }}</span>
                            </div>
                            <div class="review-card__meta">{{ $control->kind->label() }} · {{ $control->author->name }}</div>
                            <p class="review-card__text">{{ $control->body }}</p>
                        </div>
                        <div class="review-card__actions">
                            <button type="button" class="btn btn--ghost btn--small" wire:click="resolveMessage({{ $control->id }})" wire:confirm="{{ __('design_office_workflow.queue.confirm_resolve') }}">{{ __('design_office_workflow.queue.resolve') }}</button>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    @if ($isSupervisor)
        @if ($handovers->isNotEmpty())
            <section class="settings-card review-queue__section">
                <h2 class="settings-card__title">{{ __('design_office_workflow.queue.handovers') }}</h2>
                <div class="review-list">
                    @foreach ($handovers as $box)
                        <article class="review-card" wire:key="handover-{{ $box->id }}">
                            <div class="review-card__main">
                                <div class="review-card__title">
                                    <span class="review-card__box">{{ $box->name }}</span>
                                    <span class="review-card__cabinet">{{ $box->audit->cabinet_key }}</span>
                                </div>
                                <div class="review-card__meta">{{ __('design_office_workflow.queue.handover_from', ['name' => $box->responsible?->name ?? '—']) }}</div>
                            </div>
                            <div class="review-card__actions">
                                <select class="field__input review-card__select" aria-label="{{ __('design_office_workflow.queue.designate') }}" wire:change="designate({{ $box->id }}, $event.target.value)">
                                    <option value="">{{ __('design_office_workflow.queue.designate') }}</option>
                                    @foreach ($technicians as $technician)
                                        @if ($technician->id !== $box->responsible_id)
                                            <option value="{{ $technician->id }}">{{ $technician->name }}@if ($box->participants->contains('id', $technician->id)) ★@endif</option>
                                        @endif
                                    @endforeach
                                </select>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="settings-card review-queue__section">
            <h2 class="settings-card__title">{{ __('design_office_workflow.queue.reviewers') }}</h2>
            @forelse ($reviewers as $reviewer)
                <div class="module-row" wire:key="reviewer-{{ $reviewer->id }}">
                    <span class="module-row__text">
                        <span class="module-row__label">{{ $reviewer->name }}</span>
                        <small>{{ trans_choice('design_office_workflow.queue.load', $loads[$reviewer->id] ?? 0, ['count' => $loads[$reviewer->id] ?? 0]) }}</small>
                    </span>
                    <label class="toggle-switch">
                        <input type="checkbox" @checked($reviewer->is_available_for_review) wire:click="toggleAvailability({{ $reviewer->id }})" aria-label="{{ __('design_office_workflow.queue.available_for', ['name' => $reviewer->name]) }}">
                        <span></span>
                    </label>
                </div>
            @empty
                <div class="empty-state">{{ __('design_office_workflow.queue.no_reviewers') }}</div>
            @endforelse
        </section>

        <section class="settings-card review-queue__section">
            <h2 class="settings-card__title">{{ __('design_office_workflow.queue.recent_decisions') }}</h2>
            @if ($decisions->isEmpty())
                <div class="empty-state">{{ __('design_office_workflow.queue.no_decisions') }}</div>
            @else
                <div class="review-list">
                    @foreach ($decisions as $decision)
                        <article class="review-card" wire:key="decision-{{ $decision->id }}">
                            <div class="review-card__main">
                                <div class="review-card__title">
                                    <span class="review-card__box">{{ $decision->request->box->name }}</span>
                                    <span class="review-card__cabinet">{{ $decision->request->box->audit->cabinet_key }}</span>
                                </div>
                                <div class="review-card__meta">
                                    <span class="review-outcome review-outcome--{{ $decision->type->outcome->value }}">{{ $decision->type->displayLabel() }}</span>
                                    · {{ $decision->decider->name }} · {{ $decision->decided_at->timezone(config('platform.timezone'))->format('H:i') }}
                                    · {{ __('design_office_workflow.queue.technician', ['name' => $decision->request->submission->submitter->name]) }}
                                </div>
                                @if ($decision->reason)
                                    <p class="review-card__text">{{ $decision->reason }}</p>
                                @endif
                            </div>
                            <div class="review-card__actions">
                                <a class="btn btn--ghost btn--small" href="{{ route('design-office.requests.show', $decision->audit_validation_request_id) }}" wire:navigate>{{ __('design_office_workflow.queue.view') }}</a>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    @endif
</div>
