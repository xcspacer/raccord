<div class="review-request">
    <a class="btn btn--ghost btn--small" href="{{ route('design-office.queue') }}" wire:navigate>← {{ __('design_office_workflow.request.back') }}</a>

    <section class="settings-card review-request__head">
        <div class="review-card__title">
            <span class="review-card__box">{{ $request->box->name }}</span>
            <span class="review-card__cabinet">{{ $request->box->audit->cabinet_key }}</span>
            <span class="review-state review-state--{{ $request->state->value }}">{{ $request->state->label() }}</span>
        </div>
        <p class="review-card__meta">
            {{ $request->box->kind->label() }}@if ($request->box->support) · {{ $request->box->support->label() }}@endif
            · {{ __('design_office_workflow.queue.submitted_by', ['name' => $request->submission->submitter->name, 'time' => $request->submission->received_at->timezone(config('platform.timezone'))->format('d/m H:i')]) }}
            · {{ $request->reviewer?->name ?? __('design_office_workflow.queue.central_queue') }}
        </p>
        <a class="btn btn--ghost btn--small" href="{{ route('audits.box', ['cabinet' => $request->box->audit->cabinet_key, 'name' => $request->box->name]) }}" target="_blank" rel="noopener">💬 {{ __('design_office_workflow.request.open_box') }}</a>
        @unless ($request->isDecided())
            <span class="review-countdown" x-data="reviewCountdown" data-deadline="{{ $request->deadline_at->toIso8601String() }}" data-late-label="{{ __('design_office_workflow.queue.late') }}" x-text="text" x-bind:class="{ 'review-countdown--late': late }"></span>
        @endunless
    </section>

    @if ($request->submission->kind === \App\Enums\AuditSubmissionKind::Repair)
        <livewire:design-office-workflow.repair-decision-form :request-id="$request->id" :key="'repairs-'.$request->id" />
    @else
    <section class="settings-card">
        <h2 class="settings-card__title">{{ __('design_office_workflow.request.photos') }}</h2>
        @forelse ($folders as $folder => $ids)
            <div class="review-folder" wire:key="folder-{{ $loop->index }}">
                <h3 class="review-folder__title">{{ $folder }} <small>({{ count($ids) }})</small></h3>
                <div class="review-thumbs">
                    @foreach ($ids as $id)
                        @if ($photos->has($id))
                            <a class="review-thumb" href="{{ route('design-office.photos.annotations', $id) }}" target="_blank" rel="noopener">
                                <img src="{{ route('audits.photos.show', [$id, 'thumb']) }}" alt="{{ $folder }}" loading="lazy">
                            </a>
                        @else
                            <span class="review-thumb review-thumb--gone">{{ __('design_office_workflow.request.photo_gone') }}</span>
                        @endif
                    @endforeach
                </div>
            </div>
        @empty
            <div class="empty-state">{{ __('design_office_workflow.request.no_photos') }}</div>
        @endforelse
    </section>

    <section class="settings-card">
        <h2 class="settings-card__title">{{ __('design_office_workflow.request.occurrences') }}</h2>
        @forelse ($snapshot['occurrences'] ?? [] as $occurrence)
            <article class="review-occurrence" wire:key="occurrence-{{ $occurrence['id'] }}">
                <div class="review-card__title">
                    <span class="review-card__box">{{ $occurrence['reference'] }}</span>
                    <span class="review-card__cabinet">{{ $occurrence['type'] }}</span>
                    <span class="review-nature review-nature--{{ $occurrence['nature'] }}">{{ \App\Enums\AuditAnomalyNature::from($occurrence['nature'])->label() }}</span>
                    <span class="review-state">{{ \App\Enums\AuditOccurrenceState::from($occurrence['state'])->label() }}</span>
                </div>
                <p class="review-card__text">{{ $occurrence['description'] }}</p>
                @if ($occurrence['pending_justification'])
                    <p class="review-occurrence__pending"><b>{{ __('design_office_workflow.request.pending') }}</b> {{ $occurrence['pending_justification'] }}</p>
                @endif
                @foreach (['before' => __('design_office_workflow.request.before'), 'after' => __('design_office_workflow.request.after')] as $role => $label)
                    @if ($occurrence[$role] !== [])
                        <div class="review-folder">
                            <h3 class="review-folder__title">{{ $label }}</h3>
                            <div class="review-thumbs">
                                @foreach ($occurrence[$role] as $id)
                                    @if ($photos->has($id))
                                        <a class="review-thumb" href="{{ route('audits.photos.show', [$id, 'full']) }}" target="_blank" rel="noopener">
                                            <img src="{{ route('audits.photos.show', [$id, 'thumb']) }}" alt="{{ $label }}" loading="lazy">
                                        </a>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach
            </article>
        @empty
            <div class="empty-state">{{ __('design_office_workflow.request.no_occurrences') }}</div>
        @endforelse
    </section>

    @if ($request->isDecided() && $request->decision)
        <section class="settings-card review-request__decided">
            <h2 class="settings-card__title">{{ __('design_office_workflow.request.decision') }}</h2>
            <p><span class="review-outcome review-outcome--{{ $request->decision->type->outcome->value }}">{{ $request->decision->type->displayLabel() }}</span></p>
            <p class="review-card__meta">{{ __('design_office_workflow.request.decided_by', ['name' => $request->decision->decider->name, 'time' => $request->decision->decided_at->timezone(config('platform.timezone'))->format('d/m/Y H:i:s')]) }}</p>
            @if ($request->decision->reason)
                <p class="review-card__text">{{ $request->decision->reason }}</p>
            @endif
            @if ($request->decision->targets)
                <ul class="review-request__targets">
                    @foreach ($request->decision->targets as $target)
                        <li>{{ $target }}</li>
                    @endforeach
                </ul>
            @endif
            @if ($proofRows->isNotEmpty())
                <ul class="review-request__targets">
                    @foreach ($proofRows as $proof)
                        <li>{{ $proof->folder->label() }} — {{ $proof->is_accepted === null ? '—' : ($proof->is_accepted ? __('design_office_workflow.request.accepted') : __('design_office_workflow.request.refused')) }}</li>
                    @endforeach
                </ul>
            @endif
        </section>
    @else
        <form class="settings-card" wire:submit="decide">
            <h2 class="settings-card__title">{{ __('design_office_workflow.request.decide') }}</h2>

            @error('decision') <div class="field-warning" role="alert">{{ $message }}</div> @enderror

            @if ($proofRows->isNotEmpty())
                <div class="field">
                    <span class="field__label">{{ __('design_office_workflow.request.missing_proofs') }}</span>
                    @foreach ($proofRows as $proof)
                        <div class="review-proof" wire:key="proof-{{ $proof->id }}">
                            <div>
                                <strong>{{ $proof->folder->label() }}</strong>
                                <p class="review-card__text">{{ $proof->justification }}</p>
                            </div>
                            <div class="segmented review-proof__choice">
                                <label><input type="radio" value="accepted" wire:model="proofs.{{ $proof->folder->value }}"> {{ __('design_office_workflow.request.accept') }}</label>
                                <label><input type="radio" value="refused" wire:model="proofs.{{ $proof->folder->value }}"> {{ __('design_office_workflow.request.refuse') }}</label>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            <fieldset class="field review-decisions">
                <legend class="field__label">{{ __('design_office_workflow.request.decision') }}</legend>
                @foreach ($types as $type)
                    <label class="review-decisions__option" wire:key="type-{{ $type->id }}">
                        <input type="radio" value="{{ $type->code }}" wire:model.live="decision">
                        <span class="review-outcome review-outcome--{{ $type->outcome->value }}">{{ $type->displayLabel() }}</span>
                    </label>
                @endforeach
            </fieldset>

            <div class="field">
                <label class="field__label" for="decision-reason">{{ __('design_office_workflow.request.reason') }}@if ($needsReason) *@endif</label>
                <textarea class="field__input" id="decision-reason" rows="3" wire:model="reason" maxlength="2000"></textarea>
                @error('reason') <p class="field__error">{{ $message }}</p> @enderror
            </div>

            @if ($needsTargets)
                <div class="field">
                    <label class="field__label" for="decision-targets">{{ __('design_office_workflow.request.targets') }} *</label>
                    <textarea class="field__input" id="decision-targets" rows="3" wire:model="targets" maxlength="5000" placeholder="{{ __('design_office_workflow.request.targets_placeholder') }}"></textarea>
                    <p class="field__hint">{{ __('design_office_workflow.request.targets_hint') }}</p>
                </div>
            @endif

            <div class="form-actions">
                <button type="submit" class="btn btn--primary" wire:loading.attr="disabled">{{ __('design_office_workflow.request.submit') }}</button>
            </div>
        </form>
    @endif
    @endif

    <livewire:design-office-workflow.box-positions-panel :box-id="$request->audit_box_id" :key="'positions-'.$request->audit_box_id" />
    <livewire:design-office-workflow.documents-panel :audit-id="$request->box->audit_id" :key="'documents-'.$request->box->audit_id" />
</div>
