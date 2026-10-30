<section class="settings-card be-positions">
    <h2 class="settings-card__title">{{ __('design_office_workflow.positions.title') }}</h2>
    <p class="field__hint">{{ __('design_office_workflow.positions.hint') }}</p>

    @error('positions') <div class="field-warning" role="alert">{{ $message }}</div> @enderror

    <div class="be-positions__list">
        <div class="be-positions__row be-positions__row--official">
            <span class="be-positions__source">{{ \App\Enums\AuditPositionSource::Official->label() }}</span>
            @if ($summary['official'])
                <span class="be-positions__coordinates">{{ number_format((float) $summary['official']->latitude, 6, '.', '') }}, {{ number_format((float) $summary['official']->longitude, 6, '.', '') }}</span>
                <span class="be-positions__meta">{{ __('design_office_workflow.positions.approved_by', ['name' => $summary['official']->approver?->name ?? '—', 'time' => $summary['official']->created_at->timezone(config('platform.timezone'))->format('d/m/Y H:i')]) }}</span>
                <button type="button" class="btn btn--primary btn--small" wire:click="openNavigation">🧭 {{ __('audit.field.navigate') }}</button>
            @else
                <span class="be-positions__meta">{{ __('design_office_workflow.positions.no_official') }}</span>
            @endif
        </div>

        @foreach (['map_origin', 'field'] as $source)
            <div class="be-positions__row" wire:key="source-{{ $source }}">
                <span class="be-positions__source">{{ \App\Enums\AuditPositionSource::from($source)->label() }}</span>
                @if ($summary[$source])
                    <span class="be-positions__coordinates">{{ number_format((float) $summary[$source]->latitude, 6, '.', '') }}, {{ number_format((float) $summary[$source]->longitude, 6, '.', '') }}</span>
                    <span class="be-positions__meta">
                        {{ $summary[$source]->recorder?->name ?? '—' }} · {{ $summary[$source]->created_at->timezone(config('platform.timezone'))->format('d/m/Y H:i') }}@if ($summary[$source]->accuracy !== null) · {{ __('design_office_workflow.positions.accuracy', ['metres' => $summary[$source]->accuracy]) }}@endif
                    </span>
                    @if ($canDecide)
                        <button type="button" class="btn btn--ghost btn--small" wire:click="approve({{ $summary[$source]->id }})" wire:loading.attr="disabled">{{ __('design_office_workflow.positions.make_official') }}</button>
                    @endif
                @else
                    <span class="be-positions__meta">{{ __('design_office_workflow.positions.none') }}</span>
                @endif
            </div>
        @endforeach
    </div>

    <h3 class="review-folder__title">{{ __('design_office_workflow.positions.proposals') }}</h3>
    @forelse ($summary['pending'] as $proposal)
        <div class="be-positions__row be-positions__row--pending" wire:key="proposal-{{ $proposal->id }}">
            <span class="be-positions__source">{{ \App\Enums\AuditPositionSource::Proposed->label() }}</span>
            <span class="be-positions__coordinates">{{ number_format((float) $proposal->latitude, 6, '.', '') }}, {{ number_format((float) $proposal->longitude, 6, '.', '') }}</span>
            <span class="be-positions__meta">{{ $proposal->recorder?->name ?? '—' }} · {{ $proposal->created_at->timezone(config('platform.timezone'))->format('d/m/Y H:i') }}</span>
            @if ($canDecide)
                @if ($rejectingId === $proposal->id)
                    <form class="be-positions__reject" wire:submit="reject">
                        <input class="field__input" type="text" maxlength="500" wire:model="rejectionReason" placeholder="{{ __('design_office_workflow.positions.rejection_reason') }}" aria-label="{{ __('design_office_workflow.positions.rejection_reason') }}">
                        @error('rejectionReason') <p class="field__error">{{ $message }}</p> @enderror
                        <button type="submit" class="btn btn--danger btn--small">{{ __('design_office_workflow.positions.reject') }}</button>
                        <button type="button" class="btn btn--ghost btn--small" wire:click="cancelRejecting">{{ __('design_office_workflow.positions.cancel') }}</button>
                    </form>
                @else
                    <span class="be-positions__actions">
                        <button type="button" class="btn btn--primary btn--small" wire:click="approve({{ $proposal->id }})" wire:loading.attr="disabled">{{ __('design_office_workflow.positions.approve') }}</button>
                        <button type="button" class="btn btn--ghost btn--small" wire:click="startRejecting({{ $proposal->id }})">{{ __('design_office_workflow.positions.reject') }}</button>
                    </span>
                @endif
            @endif
        </div>
    @empty
        <div class="empty-state">{{ __('design_office_workflow.positions.no_proposals') }}</div>
    @endforelse

    @if ($navigating && $summary['official'])
        <div class="overlay" wire:click.self="closeNavigation" wire:keydown.escape="closeNavigation">
            <div class="modal task-nav-sheet" role="dialog" aria-modal="true" aria-labelledby="be-positions-nav-title">
                <h3 id="be-positions-nav-title" class="task-nav-sheet__title">🧭 {{ __('audit.field.navigate_to', ['name' => $box->name]) }}</h3>
                <p class="task-nav-sheet__coordinates">{{ number_format((float) $summary['official']->latitude, 6, '.', '') }}, {{ number_format((float) $summary['official']->longitude, 6, '.', '') }}</p>
                <div class="task-nav-sheet__links" x-data="navigationChoice">
                    @foreach ($navigationLinks as $key => $url)
                        <a class="btn task-nav-sheet__link" x-bind:class="linkClass('{{ $key }}')" href="{{ $url }}" target="_blank" rel="noopener" x-on:click="remember('{{ $key }}')" wire:click="closeNavigation">{{ $key === 'waze' ? '🚗' : '🗺️' }} {{ __('tasks.detail.navigation.'.$key) }}</a>
                    @endforeach
                </div>
                <button type="button" class="btn btn--ghost task-nav-sheet__close" wire:click="closeNavigation">{{ __('audit.field.close') }}</button>
            </div>
        </div>
    @endif
</section>
