<div>
    @if ($open && $person)
        <div class="overlay" wire:click.self="close" wire:keydown.escape="close">
            <div class="modal" role="dialog" aria-modal="true" aria-labelledby="technician-title">
                <h2 id="technician-title">{{ __('tasks.technician_modal.title', ['name' => $person->name]) }}</h2>

                @if ($canChangeAgency)
                    <form wire:submit="applyAgency" novalidate>
                        <div class="field">
                            <label class="field__label" for="technician-agency">{{ __('work_sites.fields.agency') }}</label>
                            <select class="field__input" id="technician-agency" wire:model="agencyId">
                                @foreach ($agencies as $agency)
                                    <option value="{{ $agency->id }}">{{ $agency->name }}</option>
                                @endforeach
                            </select>
                            <p class="field__hint">{{ __('tasks.technician_modal.agency_hint', ['week' => 'S'.$monday->isoWeek]) }}</p>
                            @error('agencyId') <p class="field__error">{{ $message }}</p> @enderror
                        </div>
                        <button type="submit" class="btn btn--primary btn--small">{{ __('tasks.technician_modal.apply') }}</button>
                    </form>
                @endif

                <section class="detail-section">
                    <h3 class="detail-section__title">{{ __('tasks.technician_modal.absences') }}</h3>
                    @forelse ($periods as $period)
                        <div class="absence-block__item" wire:key="period-{{ $period['start']->toDateString() }}">
                            <span>{{ __('absences.icons.'.$period['type']->value) }} {{ $period['type']->label() }} — {{ $period['start']->translatedFormat('d M') }}@if (! $period['start']->eq($period['end'])) → {{ $period['end']->translatedFormat('d M') }}@endif {{ __('absences.days_short', ['count' => $period['days']]) }}</span>
                            @if ($canManageAbsences)
                                <span class="tasks-person__actions">
                                    <button type="button" class="icon-mini" wire:click="editAbsence('{{ $period['start']->toDateString() }}')" aria-label="{{ __('tasks.technician_modal.edit') }}">✎</button>
                                    <button type="button" class="chip-delete" wire:click="askRemoveAbsence('{{ $period['start']->toDateString() }}', '{{ $period['end']->toDateString() }}')" aria-label="{{ __('absences.remove') }}">✕</button>
                                </span>
                            @endif
                        </div>
                    @empty
                        <p class="task-empty">{{ __('tasks.technician_modal.no_absence') }}</p>
                    @endforelse
                </section>

                <div class="modal-actions">
                    <button type="button" class="btn btn--ghost" wire:click="close">{{ __('profile.close') }}</button>
                </div>
            </div>
        </div>
    @endif
</div>
