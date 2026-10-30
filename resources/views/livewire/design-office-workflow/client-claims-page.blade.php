<div class="review-queue">
    <p class="page-hint">{{ __('design_office_workflow.claims.hint') }}</p>

    <section class="settings-card review-queue__section">
        <h2 class="settings-card__title">{{ __('design_office_workflow.claims.open') }}</h2>

        @if ($open->isEmpty())
            <div class="empty-state">{{ __('design_office_workflow.claims.empty') }}</div>
        @else
            <div class="review-list">
                @foreach ($open as $claim)
                    <article @class(['review-card', 'review-card--alert' => $claim->state === \App\Enums\AuditClientClaimState::Escalated]) wire:key="claim-{{ $claim->id }}">
                        <div class="review-card__main">
                            <div class="review-card__title">
                                <span class="review-card__box">{{ $claim->box?->name ?? '—' }}</span>
                                <span class="review-card__cabinet">{{ $claim->audit->cabinet_key }}</span>
                                <span class="review-state">{{ $claim->kind->label() }}</span>
                                <span class="review-state">{{ $claim->state->label() }}</span>
                            </div>
                            <div class="review-card__meta">
                                {{ __('design_office_workflow.claims.from', ['name' => $claim->clientAccount?->name ?? '—', 'client' => $claim->clientAccount?->client?->name ?? '—', 'time' => $claim->created_at->timezone(config('platform.timezone'))->format('d/m/Y H:i')]) }}
                                @if ($claim->round > 1) · {{ __('design_office_workflow.claims.round', ['round' => $claim->round]) }}@endif
                            </div>
                            @if ($claim->reserve)
                                <p class="review-card__text"><b>{{ __('design_office_workflow.claims.reserve') }}</b> {{ $claim->reserve->reason }}</p>
                            @endif
                            <p class="review-card__text">{{ $claim->body }}</p>
                        </div>
                        <div class="review-card__actions">
                            @if ($isSupervisor || $claim->state !== \App\Enums\AuditClientClaimState::Escalated)
                                <button type="button" class="btn btn--primary btn--small" wire:click="startDecision({{ $claim->id }})">{{ __('design_office_workflow.claims.answer') }}</button>
                            @else
                                <span class="review-card__meta">{{ __('design_office_workflow.claims.supervisor_only') }}</span>
                            @endif
                        </div>

                        @if ($deciding === $claim->id)
                            <form class="review-claims__form" wire:submit="decide">
                                @error('verdict') <div class="field-warning" role="alert">{{ $message }}</div> @enderror
                                <div class="segmented">
                                    <label><input type="radio" value="accept" wire:model.live="verdict"> {{ $claim->kind === $newOccurrence ? __('design_office_workflow.claims.confirm_occurrence') : __('design_office_workflow.claims.accept') }}</label>
                                    <label><input type="radio" value="refuse" wire:model.live="verdict"> {{ __('design_office_workflow.claims.refuse') }}</label>
                                </div>
                                @if ($claim->kind === $newOccurrence && $verdict === 'accept')
                                    <div class="field">
                                        <label class="field__label" for="claim-type-{{ $claim->id }}">{{ __('design_office_workflow.claims.anomaly_type') }} *</label>
                                        <select class="field__input" id="claim-type-{{ $claim->id }}" wire:model="anomalyType">
                                            <option value="">—</option>
                                            @foreach ($anomalyTypes as $type)
                                                <option value="{{ $type->id }}">{{ $type->displayLabel() }} ({{ $type->nature->label() }})</option>
                                            @endforeach
                                        </select>
                                    </div>
                                @endif
                                <div class="field">
                                    <label class="field__label" for="claim-reason-{{ $claim->id }}">{{ __('design_office_workflow.claims.reason') }}@if ($verdict === 'refuse') *@endif</label>
                                    <textarea class="field__input" id="claim-reason-{{ $claim->id }}" rows="3" maxlength="2000" wire:model="reason"></textarea>
                                    <small class="field__hint">{{ __('design_office_workflow.claims.reason_hint') }}</small>
                                </div>
                                <div class="review-card__actions">
                                    <button type="submit" class="btn btn--primary btn--small">{{ __('design_office_workflow.claims.submit') }}</button>
                                    <button type="button" class="btn btn--ghost btn--small" wire:click="startDecision({{ $claim->id }})">{{ __('design_office_workflow.claims.cancel') }}</button>
                                </div>
                            </form>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    <section class="settings-card review-queue__section">
        <h2 class="settings-card__title">{{ __('design_office_workflow.claims.answered') }}</h2>
        @if ($answered->isEmpty())
            <div class="empty-state">{{ __('design_office_workflow.claims.none_answered') }}</div>
        @else
            <div class="review-list">
                @foreach ($answered as $claim)
                    <article class="review-card" wire:key="answered-{{ $claim->id }}">
                        <div class="review-card__main">
                            <div class="review-card__title">
                                <span class="review-card__box">{{ $claim->box?->name ?? '—' }}</span>
                                <span class="review-card__cabinet">{{ $claim->audit->cabinet_key }}</span>
                                <span class="review-state">{{ $claim->kind->label() }}</span>
                                <span class="review-state">{{ $claim->state->label() }}</span>
                                @if ($claim->occurrence)<span class="review-card__cabinet">{{ $claim->occurrence->reference }}</span>@endif
                            </div>
                            <div class="review-card__meta">{{ __('design_office_workflow.request.decided_by', ['name' => $claim->decider?->name ?? '—', 'time' => $claim->decided_at?->timezone(config('platform.timezone'))->format('d/m/Y H:i')]) }}</div>
                            <p class="review-card__text">{{ $claim->body }}</p>
                            @if ($claim->decision_reason)
                                <p class="review-card__text"><b>{{ __('design_office_workflow.claims.reason') }}:</b> {{ $claim->decision_reason }}</p>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
</div>
