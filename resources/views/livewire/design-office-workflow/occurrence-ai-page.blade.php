<div class="review-request">
    <a class="btn btn--ghost btn--small" href="{{ route('design-office.occurrences.show', $occurrence) }}" wire:navigate>← {{ __('design_office_workflow.ai.back') }}</a>
    <section class="settings-card review-request__head">
        <div class="review-card__title">
            <span class="review-card__box">{{ $occurrence->reference }}</span>
            <span class="review-card__cabinet">{{ $occurrence->audit->cabinet_key }} · {{ $occurrence->box->name }}</span>
            <span class="review-nature review-nature--{{ $occurrence->nature->value }}">{{ $occurrence->nature->label() }}</span>
            <span class="review-state">{{ $occurrence->state->label() }}</span>
        </div>
        <p class="review-card__text">{{ $occurrence->type->displayLabel() }} — {{ $occurrence->description }}</p>
    </section>

    <section class="settings-card">
        <h2 class="settings-card__title">{{ __('design_office_workflow.ai.scope') }}</h2>
        <p class="page-hint">{{ __('design_office_workflow.ai.scope_hint', ['count' => $scope->count(), 'language' => __('platform.languages.'.$locale)]) }}</p>
        @if ($scope->isEmpty())
            <div class="empty-state">{{ __('design_office_workflow.ai.no_photos') }}</div>
        @else
            <div class="review-thumbs">
                @foreach ($scope as $photo)
                    <a class="review-thumb" href="{{ route('audits.photos.show', [$photo->id, 'full']) }}" target="_blank" rel="noopener" wire:key="scope-{{ $photo->id }}">
                        <img src="{{ route('audits.photos.show', [$photo->id, 'thumb']) }}" alt="" loading="lazy">
                    </a>
                @endforeach
            </div>
        @endif

        @error('generate') <div class="field-warning" role="alert">{{ $message }}</div> @enderror

        @if ($enabled)
            <div class="form-actions">
                <button type="button" class="btn btn--primary" wire:click="generate" wire:loading.attr="disabled" @disabled($scope->isEmpty())>✨ {{ __('design_office_workflow.ai.generate') }}</button>
            </div>
        @else
            <div class="field-warning">{{ __('design_office_workflow.ai.disabled') }}</div>
        @endif
    </section>

    @if ($published)
        <section class="settings-card">
            <h2 class="settings-card__title">{{ __('design_office_workflow.ai.published') }}</h2>
            <p class="review-card__meta">{{ __('design_office_workflow.ai.approved_by', ['name' => $published->approver?->name ?? '—', 'time' => $published->approved_at?->timezone(config('platform.timezone'))->format('d/m/Y H:i')]) }}</p>
            <p class="review-ai__text">{{ $published->text }}</p>
        </section>
    @endif

    <section class="settings-card">
        <h2 class="settings-card__title">{{ __('design_office_workflow.ai.history') }}</h2>
        @forelse ($history as $draft)
            <article class="review-occurrence" wire:key="draft-{{ $draft->id }}">
                <div class="review-card__title">
                    <span class="review-state review-ai__status--{{ $draft->status->value }}">{{ $draft->status->label() }}</span>
                    <span class="review-card__meta">
                        {{ __('design_office_workflow.ai.requested_by', ['name' => $draft->requester?->name ?? '—', 'time' => $draft->created_at->timezone(config('platform.timezone'))->format('d/m/Y H:i')]) }}
                        · {{ __('platform.languages.'.$draft->locale) }}
                        @if ($draft->editor) · {{ __('design_office_workflow.ai.edited_by', ['name' => $draft->editor->name]) }}@endif
                    </span>
                </div>
                @if ($draft->status === \App\Enums\AuditAiDraftStatus::Draft)
                    <div class="field">
                        <label class="field__label" for="draft-text-{{ $draft->id }}">{{ __('design_office_workflow.ai.text') }}</label>
                        <textarea class="field__input" id="draft-text-{{ $draft->id }}" rows="12" maxlength="{{ \App\DesignOfficeWorkflow\AiDrafts::MAX_TEXT }}" wire:model="texts.{{ $draft->id }}"></textarea>
                        @error('texts.'.$draft->id) <p class="field__error">{{ $message }}</p> @enderror
                    </div>
                    <div class="review-card__actions">
                        <button type="button" class="btn btn--ghost btn--small" wire:click="save({{ $draft->id }})">{{ __('design_office_workflow.ai.save') }}</button>
                        <button type="button" class="btn btn--primary btn--small" wire:click="approve({{ $draft->id }})" wire:confirm="{{ __('design_office_workflow.ai.confirm_approve') }}">{{ __('design_office_workflow.ai.approve') }}</button>
                        <button type="button" class="btn btn--ghost btn--small" wire:click="reject({{ $draft->id }})" wire:confirm="{{ __('design_office_workflow.ai.confirm_reject') }}">{{ __('design_office_workflow.ai.reject') }}</button>
                    </div>
                @else
                    <p class="review-ai__text">{{ $draft->text }}</p>
                @endif
            </article>
        @empty
            <div class="empty-state">{{ __('design_office_workflow.ai.no_drafts') }}</div>
        @endforelse
    </section>
</div>
