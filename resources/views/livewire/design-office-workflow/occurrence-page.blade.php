<div class="review-request">
    <div class="review-request__top">
        <a class="btn btn--ghost btn--small" href="{{ route('design-office.occurrences', ['audit' => $occurrence->audit_id]) }}" wire:navigate>← {{ __('design_office_workflow.occurrence.back') }}</a>
        <livewire:design-office-workflow.declare-occurrence-form :preset-audit-id="$occurrence->audit_id" :preset-box-id="$occurrence->audit_box_id" :key="'declare-'.$occurrence->id" />
    </div>

    @error('action') <div class="field-warning" role="alert">{{ $message }}</div> @enderror

    <section class="settings-card review-request__head">
        <div class="review-card__title">
            <span class="review-card__box">{{ $occurrence->reference }}</span>
            <span class="review-card__cabinet">{{ $occurrence->audit->cabinet_key }} · {{ $occurrence->box->name }}</span>
            <span class="review-nature review-nature--{{ $occurrence->nature->value }}">{{ $occurrence->nature->label() }}</span>
            <span class="occurrence-state occurrence-state--{{ $occurrence->state->value }}">{{ $occurrence->state->label() }}</span>
        </div>
        <p class="review-card__meta">
            {{ $occurrence->type->displayLabel() }} · {{ $occurrence->source->label() }}
            · {{ __('design_office_workflow.occurrence.declared_by', ['name' => $occurrence->creator?->name ?? '—', 'time' => $occurrence->created_at->timezone(config('platform.timezone'))->format('d/m/Y H:i')]) }}
        </p>
        <p class="review-card__text">{{ $occurrence->description }}</p>
        @can('create', [\App\Models\AuditAiDraft::class, $occurrence])
            <a class="btn btn--ghost btn--small" href="{{ route('design-office.occurrences.ai', $occurrence) }}" wire:navigate>✨ {{ __('design_office_workflow.ai.open') }}</a>
        @endcan
        @if ($occurrence->location_note)
            <p class="review-card__meta">{{ __('design_office_workflow.occurrence.location') }} {{ $occurrence->location_note }}</p>
        @endif
    </section>

    @if ($occurrence->isPending() || $occurrence->action_taken)
        <section class="settings-card">
            @if ($occurrence->isPending())
                <h2 class="settings-card__title">{{ __('design_office_workflow.occurrence.on_hold') }}</h2>
                <p class="review-card__text">{{ $occurrence->pending_justification }}</p>
                <p class="review-card__meta">
                    @if ($occurrence->pending_authorized_at)
                        {{ __('design_office_workflow.occurrence.continuation_authorized', ['name' => $occurrence->pendingAuthorizer?->name ?? '—', 'time' => $occurrence->pending_authorized_at->timezone(config('platform.timezone'))->format('d/m/Y H:i')]) }}
                    @else
                        {{ __('design_office_workflow.occurrence.continuation_waiting') }}
                    @endif
                </p>
            @endif
            @if ($occurrence->action_taken)
                <h2 class="settings-card__title">{{ __('design_office_workflow.occurrence.correction') }}</h2>
                <p class="review-card__text">{{ $occurrence->action_taken }}</p>
                @if ($occurrence->checks_done)
                    <p class="review-card__meta">{{ __('design_office_workflow.occurrence.checks') }} {{ $occurrence->checks_done }}</p>
                @endif
                @if ($occurrence->observations)
                    <p class="review-card__meta">{{ __('design_office_workflow.occurrence.observations') }} {{ $occurrence->observations }}</p>
                @endif
                @if ($occurrence->corrected_at)
                    <p class="review-card__meta">{{ __('design_office_workflow.occurrence.corrected_by', ['name' => $occurrence->corrector?->name ?? '—', 'time' => $occurrence->corrected_at->timezone(config('platform.timezone'))->format('d/m/Y H:i')]) }}</p>
                @endif
            @endif
            @if ($occurrence->validated_at)
                <p class="review-card__meta">{{ __('design_office_workflow.occurrence.validated_by', ['name' => $occurrence->validator?->name ?? '—', 'time' => $occurrence->validated_at->timezone(config('platform.timezone'))->format('d/m/Y H:i')]) }}</p>
            @endif
        </section>
    @endif

    <section class="settings-card">
        <h2 class="settings-card__title">{{ __('design_office_workflow.occurrence.photos') }}</h2>
        @forelse ($photos as $role => $roleLinks)
            <div class="review-folder" wire:key="role-{{ $role }}">
                <h3 class="review-folder__title">{{ \App\Enums\AuditOccurrencePhotoRole::from($role)->label() }} <small>({{ $roleLinks->count() }})</small></h3>
                <div class="review-thumbs">
                    @foreach ($roleLinks as $link)
                        <a class="review-thumb" href="{{ route('audits.photos.show', [$link->audit_photo_id, 'full']) }}" target="_blank" rel="noopener" wire:key="photo-{{ $link->id }}">
                            <img src="{{ route('audits.photos.show', [$link->audit_photo_id, 'thumb']) }}" alt="{{ \App\Enums\AuditOccurrencePhotoRole::from($role)->label() }}" loading="lazy">
                        </a>
                    @endforeach
                </div>
            </div>
        @empty
            <div class="empty-state">{{ __('design_office_workflow.occurrence.no_photos') }}</div>
        @endforelse
    </section>

    <section class="settings-card">
        <h2 class="settings-card__title">{{ __('design_office_workflow.occurrence.boxes') }}</h2>
        <div class="review-list">
            @foreach ($links as $link)
                @php($state = $linkState($link))
                <article @class(['review-card', 'occurrence-box', 'occurrence-box--'.$state]) wire:key="link-{{ $link->id }}">
                    <div class="review-card__main">
                        <div class="review-card__title">
                            <span class="review-card__box">{{ $link->box->name }}</span>
                            <span class="occurrence-flag occurrence-flag--{{ $state }}">{{ __('design_office_workflow.occurrence.link_states.'.$state) }}</span>
                            @if (in_array($state, ['origin', 'confirmed'], true))
                                <span class="occurrence-state occurrence-state--{{ $link->conformity->value }}">{{ $link->conformity->label() }}</span>
                            @endif
                        </div>
                        @if ($link->justification)
                            <p class="review-card__text">{{ $link->justification }}</p>
                        @endif
                        @if ($state === 'excluded')
                            <p class="review-card__meta">{{ __('design_office_workflow.occurrence.excluded_by', ['name' => $link->excluder?->name ?? '—']) }} — {{ $link->exclusion_reason }}</p>
                        @elseif ($state === 'confirmed' && $link->confirmer)
                            <p class="review-card__meta">{{ __('design_office_workflow.occurrence.confirmed_by', ['name' => $link->confirmer->name]) }}</p>
                        @endif
                        @if ($link->note)
                            <p class="review-card__meta">{{ $link->note }}</p>
                        @endif
                    </div>

                    @unless ($occurrence->state->isClosed() || $occurrence->audit->isClosed())
                        <div class="review-card__actions">
                            @if ($state === 'proposed')
                                <button type="button" class="btn btn--primary btn--small" wire:click="confirmBox({{ $link->audit_box_id }})">{{ __('design_office_workflow.occurrence.confirm') }}</button>
                            @endif
                            @if (in_array($state, ['proposed', 'confirmed'], true))
                                <button type="button" class="btn btn--ghost btn--small" wire:click="startExclusion({{ $link->audit_box_id }})">{{ __('design_office_workflow.occurrence.exclude') }}</button>
                            @endif
                        </div>
                    @endunless

                    @if (in_array($state, ['origin', 'confirmed'], true) && ! $occurrence->audit->isClosed())
                        <form class="occurrence-result" wire:submit="saveResult({{ $link->audit_box_id }})">
                            <select class="field__input occurrence-result__select" wire:model="results.{{ $link->audit_box_id }}" aria-label="{{ __('design_office_workflow.occurrence.result') }}">
                                @foreach ($conformities as $conformity)
                                    <option value="{{ $conformity->value }}">{{ $conformity->label() }}</option>
                                @endforeach
                            </select>
                            <input class="field__input occurrence-result__note" type="text" maxlength="1000" wire:model="notes.{{ $link->audit_box_id }}" placeholder="{{ __('design_office_workflow.occurrence.note') }}" aria-label="{{ __('design_office_workflow.occurrence.note') }}">
                            <button type="submit" class="btn btn--ghost btn--small">{{ __('design_office_workflow.occurrence.save_result') }}</button>
                        </form>
                    @endif

                    @if ($excludingBoxId === $link->audit_box_id)
                        <form class="occurrence-result" wire:submit="excludeBox">
                            <input class="field__input occurrence-result__note" type="text" maxlength="2000" wire:model="exclusionReason" placeholder="{{ __('design_office_workflow.occurrence.exclusion_reason') }}" aria-label="{{ __('design_office_workflow.occurrence.exclusion_reason') }}">
                            <button type="submit" class="btn btn--danger btn--small">{{ __('design_office_workflow.occurrence.exclude') }}</button>
                            <button type="button" class="btn btn--ghost btn--small" wire:click="cancelExclusion">{{ __('design_office_workflow.occurrence.cancel') }}</button>
                            @error('exclusionReason') <p class="field__error">{{ $message }}</p> @enderror
                        </form>
                    @endif
                </article>
            @endforeach
        </div>

        @if ($canAddBox && $candidates->isNotEmpty())
            <form class="occurrence-add" wire:submit="addBox">
                <h3 class="review-folder__title">{{ __('design_office_workflow.occurrence.add_box') }}</h3>
                <div class="field">
                    <label class="field__label" for="occurrence-new-box">{{ __('design_office_workflow.occurrence.box') }}</label>
                    <select class="field__input" id="occurrence-new-box" wire:model="newBoxId">
                        <option value="">—</option>
                        @foreach ($candidates as $candidate)
                            <option value="{{ $candidate->id }}">{{ $candidate->name }}</option>
                        @endforeach
                    </select>
                    @error('newBoxId') <p class="field__error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label class="field__label" for="occurrence-new-justification">{{ __('design_office_workflow.occurrence.justification') }}</label>
                    <textarea class="field__input" id="occurrence-new-justification" rows="2" maxlength="2000" wire:model="newBoxJustification"></textarea>
                    @error('newBoxJustification') <p class="field__error">{{ $message }}</p> @enderror
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn--primary">{{ __('design_office_workflow.occurrence.add') }}</button>
                </div>
            </form>
        @endif
    </section>

    <section class="settings-card">
        <h2 class="settings-card__title">{{ __('design_office_workflow.occurrence.journal') }}</h2>
        @if ($journal === [])
            <div class="empty-state">{{ __('design_office_workflow.occurrence.no_journal') }}</div>
        @else
            <ol class="occurrence-journal">
                @foreach ($journal as $entry)
                    <li class="occurrence-journal__entry" wire:key="journal-{{ $loop->index }}">
                        <span class="occurrence-journal__at">{{ $entry['at'] }}</span>
                        <span class="occurrence-journal__event">{{ $entry['event'] }}</span>
                        <span class="occurrence-journal__by">{{ $entry['by'] }}</span>
                        @if ($entry['reason'])
                            <span class="occurrence-journal__reason">{{ $entry['reason'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif
    </section>
</div>
