<div class="audit" wire:poll.60s.visible>
    <div class="audit__head audit__head--end">
        @if ($isManager)
            <button type="button" class="btn btn--ghost" wire:click="askNewPm">＋ {{ __('audit.add_pm') }}</button>
        @endif
        @include('livewire.audit.partials.sync-chip')
    </div>

    @include('livewire.audit.partials.client-notes')

    <div class="audit__grid">
        @forelse ($cards as $card)
            <div class="audit__card-wrap" wire:key="audit-{{ $card['key'] }}">
                @if ($isManager)
                    <button type="button" class="audit__card-delete" wire:click="askArchive('{{ $card['key'] }}')" title="{{ __('audit.archive.button') }}" aria-label="{{ __('audit.archive.button') }}">🗑</button>
                @endif
                <a class="audit__card" href="{{ route('audits.show', ['cabinet' => $card['key']]) }}" wire:click.prevent="open('{{ $card['key'] }}')">
                    <div class="audit__card-head">
                        <strong class="audit__reference">{{ $card['reference'] }}</strong>
                        @if ($card['mode'])
                            <span class="audit__chip audit__chip--{{ $card['mode']->value }}">{{ $card['mode']->label() }}</span>
                        @endif
                        @if ($card['state'] !== 'in_progress')
                            <span class="audit__chip audit__chip--{{ $card['state'] }}">{{ __('audit.states.'.$card['state']) }}</span>
                        @endif
                    </div>
                    <span class="audit__muted">
                        {{ collect([$card['city'], $card['starts_on'] ? $card['starts_on']->translatedFormat('d M').($card['ends_on'] && ! $card['ends_on']->isSameDay($card['starts_on']) ? ' → '.$card['ends_on']->translatedFormat('d M') : '') : null])->filter()->implode(' · ') }}
                    </span>
                    @if ($card['sro'])<span class="audit__card-sro">{{ $card['sro'] }}</span>@endif
                    @if ($card['state'] !== 'not_configured')
                        <div class="audit__bar" role="progressbar" aria-valuenow="{{ $card['percent'] }}" aria-valuemin="0" aria-valuemax="100"><span class="audit__bar-fill" data-vars="width:{{ $card['percent'] }}%"></span></div>
                        <span class="audit__muted">{{ __('audit.boxes_done', ['done' => $card['boxes_done'], 'total' => $card['boxes_total']]) }}</span>
                    @endif
                </a>
            </div>
        @empty
            <div class="empty-state">{{ $isManager ? __('audit.empty_manager') : __('audit.empty_technician') }}</div>
        @endforelse
    </div>

    @if ($addingPm)
        <div class="overlay" wire:click.self="cancelNewPm" wire:keydown.escape="cancelNewPm">
            <form class="modal" role="dialog" aria-modal="true" aria-labelledby="new-pm-title" wire:submit="openPm">
                <h2 id="new-pm-title">＋ {{ __('audit.new_pm.title', ['mode' => \App\Enums\AuditMode::from($mode)->label()]) }}</h2>
                <div class="field">
                    <label class="field__label" for="new-pm">{{ __('audit.new_pm.label') }}</label>
                    <input class="field__input" id="new-pm" wire:model="newPm" maxlength="30" placeholder="{{ __('audit.new_pm.placeholder') }}" autocapitalize="characters" autocomplete="off" spellcheck="false" autofocus>
                    <p class="field__hint">{{ __('audit.new_pm.hint') }}</p>
                    @error('newPm') <p class="field__error">{{ $message }}</p> @enderror
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn--ghost" wire:click="cancelNewPm">{{ __('audit.field.cancel') }}</button>
                    <button type="submit" class="btn btn--primary">{{ __('audit.new_pm.continue') }}</button>
                </div>
            </form>
        </div>
    @endif

    @if ($archiving)
        <div class="modal" role="dialog" aria-modal="true">
            <form class="modal__panel" wire:submit="archive">
                <p>{{ __('audit.archive.question', ['cabinet' => $archiving]) }}</p>
                <label class="field__label" for="archive-code">{{ __('audit.archive.type', ['cabinet' => $archiving]) }}</label>
                <input class="field__input" id="archive-code" wire:model="archiveCode" autocomplete="off" autofocus>
                @error('archiveCode') <p class="field__error">{{ $message }}</p> @enderror
                <div class="form-actions">
                    <button type="button" class="btn btn--ghost" wire:click="cancelArchive">{{ __('audit.field.cancel') }}</button>
                    <button type="submit" class="btn btn--primary btn--danger">{{ __('audit.archive.confirm') }}</button>
                </div>
            </form>
        </div>
    @endif
</div>
