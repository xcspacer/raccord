<div>
    @if ($open)
        <div class="overlay" wire:keydown.escape="close">
            <div class="modal modal--wide" role="dialog" aria-modal="true" aria-labelledby="work-site-title">
                <h2 id="work-site-title">{{ $editingId ? __('work_sites.edit_title') : __('work_sites.create_title') }}</h2>

                <form wire:submit="save" novalidate>
                    <div class="field-row">
                        <div class="field">
                            <label class="field__label" for="ws-agency">{{ __('work_sites.fields.agency') }} *</label>
                            <select class="field__input" id="ws-agency" wire:model.live="form.agencyId" @disabled($editingId)>
                                @foreach ($agencies as $agency)
                                    <option value="{{ $agency->id }}">{{ $agency->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        @if ($isDaily)
                            <div class="field">
                                <label class="field__label" for="ws-project">{{ __('work_sites.fields.project') }}</label>
                                <select class="field__input" id="ws-project" wire:model.live="daily.project">
                                    <option value="swap">{{ __('enums.project_types.swap') }}</option>
                                    <option value="nro">{{ __('enums.project_types.nro') }}</option>
                                    <option value="audit">{{ __('work_sites.project_audit') }}</option>
                                    <option value="audit:optical">{{ __('work_sites.project_audit_optical') }}</option>
                                    <option value="audit:full">{{ __('work_sites.project_audit_full') }}</option>
                                </select>
                            </div>
                        @endif
                    </div>

                    @if ($isDaily && $isAudit)
                        @include('livewire.work-sites.partials.audit-form')
                    @elseif ($isDaily)
                        @include('livewire.work-sites.partials.daily-form')
                    @else
                        @include('livewire.work-sites.partials.detailed-form')
                    @endif

                    <div class="modal-actions">
                        <button type="button" class="btn btn--ghost" wire:click="close">{{ __('work_sites.cancel') }}</button>
                        <button type="submit" class="btn btn--primary" wire:loading.attr="disabled" wire:target="save,kmzFile,synopticFile,drawerPlan,boxPlans">{{ __('work_sites.save') }}</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
