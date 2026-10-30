<section class="settings-card be-documents">
    <h2 class="settings-card__title">{{ __('design_office_workflow.documents.title', ['cabinet' => $audit->cabinet_key]) }}</h2>
    <p class="field__hint">{{ __('design_office_workflow.documents.hint') }}</p>

    @foreach ($kinds as $documentKind)
        <div class="be-documents__kind" wire:key="kind-{{ $documentKind->value }}">
            <h3 class="review-folder__title">{{ $documentKind->label() }}</h3>
            @forelse ($versions->get($documentKind->value, []) as $version)
                <div class="be-documents__version" wire:key="version-{{ $version->id }}">
                    <span class="be-documents__number">{{ __('design_office_workflow.documents.version', ['number' => $version->version_number]) }}</span>
                    @if ($version->is_current)
                        <span class="be-documents__current">{{ __('design_office_workflow.documents.current') }}</span>
                    @endif
                    <a class="be-documents__name" href="{{ route('design-office.documents.download', $version) }}">{{ $version->original_name }}</a>
                    <span class="be-documents__meta">
                        {{ $version->publisher?->name ?? '—' }} · {{ $version->published_at->timezone(config('platform.timezone'))->format('d/m/Y H:i') }}
                        @if ($documentKind === \App\Enums\AuditDocumentKind::Map && isset($version->summary['matched']))
                            · {{ __('design_office_workflow.documents.matched', ['matched' => $version->summary['matched'], 'total' => $version->summary['placemarks'] ?? 0]) }}
                        @endif
                        @if ($documentKind === \App\Enums\AuditDocumentKind::Synoptic && ! empty($version->summary['corrections']))
                            · {{ trans_choice('design_office_workflow.documents.includes', count($version->summary['corrections']), ['count' => count($version->summary['corrections'])]) }}
                        @endif
                    </span>
                </div>
            @empty
                <div class="empty-state">{{ __('design_office_workflow.documents.none') }}</div>
            @endforelse
        </div>
    @endforeach

    @if ($mapResult !== null && $mapResult['unmatched'] !== [])
        <div class="field-warning" role="status">
            {{ __('design_office_workflow.documents.unmatched', ['names' => implode(', ', $mapResult['unmatched'])]) }}
        </div>
    @endif

    @if ($canEdit)
        <form class="be-documents__form" wire:submit="publish">
            <h3 class="review-folder__title">{{ __('design_office_workflow.documents.publish_title') }}</h3>
            @error('document') <div class="field-warning" role="alert">{{ $message }}</div> @enderror

            <div class="field">
                <label class="field__label" for="be-documents-kind">{{ __('design_office_workflow.documents.kind') }}</label>
                <select class="field__input" id="be-documents-kind" wire:model.live="kind">
                    @foreach ($kinds as $documentKind)
                        <option value="{{ $documentKind->value }}">{{ $documentKind->label() }}</option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <span class="field__label">{{ __('design_office_workflow.documents.file') }}</span>
                <label class="file-picker">
                    <span class="btn btn--ghost">{{ __('design_office_workflow.documents.choose_file') }}</span>
                    <input class="file-picker__input" type="file" accept="{{ $accept }}" wire:model="document">
                </label>
                @if ($document)
                    <p class="field__hint">{{ $document->getClientOriginalName() }}</p>
                @endif
                <p class="field__hint">{{ $kind === 'map' ? __('design_office_workflow.documents.map_hint') : __('design_office_workflow.documents.synoptic_hint') }}</p>
            </div>

            @if ($kind === 'synoptic' && $openCorrections->isNotEmpty())
                <fieldset class="field">
                    <legend class="field__label">{{ __('design_office_workflow.documents.include_corrections') }}</legend>
                    @foreach ($openCorrections as $correction)
                        <label class="be-documents__include" wire:key="include-{{ $correction->id }}">
                            <input type="checkbox" value="{{ $correction->id }}" wire:model="included">
                            <span>{{ $correction->box?->name ?? $audit->cabinet_key }} — {{ \Illuminate\Support\Str::limit($correction->body, 80) }}</span>
                        </label>
                    @endforeach
                </fieldset>
            @endif

            <div class="form-actions">
                <button type="submit" class="btn btn--primary" wire:loading.attr="disabled">{{ __('design_office_workflow.documents.publish') }}</button>
            </div>
        </form>
    @else
        <p class="field__hint">{{ __('design_office_workflow.documents.closed') }}</p>
    @endif

    <h3 class="review-folder__title">{{ __('design_office_workflow.documents.corrections') }}</h3>
    @error('corrections') <div class="field-warning" role="alert">{{ $message }}</div> @enderror
    @forelse ($openCorrections as $correction)
        <article class="be-documents__correction" wire:key="correction-{{ $correction->id }}">
            <div class="review-card__title">
                <span class="review-card__box">{{ $correction->box?->name ?? $audit->cabinet_key }}</span>
                <span class="review-state">{{ $correction->state->label() }}</span>
            </div>
            <p class="review-card__meta">{{ $correction->proposer->name }} · {{ $correction->created_at->timezone(config('platform.timezone'))->format('d/m/Y H:i') }}</p>
            <p class="review-card__text">{{ $correction->body }}</p>
            @if ($correction->audit_photo_id)
                <a class="review-thumb" href="{{ route('audits.photos.show', [$correction->audit_photo_id, 'full']) }}" target="_blank" rel="noopener">
                    <img src="{{ route('audits.photos.show', [$correction->audit_photo_id, 'thumb']) }}" alt="{{ __('design_office_workflow.documents.correction_photo') }}" loading="lazy">
                </a>
            @endif
            @if ($canEdit)
                @if ($rejectingId === $correction->id)
                    <form class="be-documents__reject" wire:submit="rejectCorrection">
                        <input class="field__input" type="text" maxlength="1000" wire:model="rejectionReason" placeholder="{{ __('design_office_workflow.documents.rejection_reason') }}" aria-label="{{ __('design_office_workflow.documents.rejection_reason') }}">
                        @error('rejectionReason') <p class="field__error">{{ $message }}</p> @enderror
                        <button type="submit" class="btn btn--danger btn--small">{{ __('design_office_workflow.documents.reject') }}</button>
                        <button type="button" class="btn btn--ghost btn--small" wire:click="cancelRejecting">{{ __('design_office_workflow.positions.cancel') }}</button>
                    </form>
                @else
                    <button type="button" class="btn btn--ghost btn--small" wire:click="startRejecting({{ $correction->id }})">{{ __('design_office_workflow.documents.reject') }}</button>
                @endif
            @endif
        </article>
    @empty
        <div class="empty-state">{{ __('design_office_workflow.documents.no_corrections') }}</div>
    @endforelse

    @if ($decidedCorrections->isNotEmpty())
        <ul class="be-documents__decided">
            @foreach ($decidedCorrections as $correction)
                <li wire:key="decided-{{ $correction->id }}">
                    <span class="review-state">{{ $correction->state->label() }}</span>
                    {{ $correction->box?->name ?? $audit->cabinet_key }} — {{ \Illuminate\Support\Str::limit($correction->body, 80) }}
                    @if ($correction->documentVersion)
                        ({{ __('design_office_workflow.documents.version', ['number' => $correction->documentVersion->version_number]) }})
                    @elseif ($correction->decision_note)
                        ({{ $correction->decision_note }})
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</section>
