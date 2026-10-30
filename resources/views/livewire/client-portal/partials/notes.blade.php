<div class="portal-toolbar">
    <h2 class="portal-notes__title">{{ __('client_portal.notes.title', ['company' => $company]) }}</h2>
    <span class="portal-toolbar__count">{{ __('client_portal.notes.counts', ['open' => $openRemarks, 'total' => $remarks->count()]) }}</span>
</div>

<form class="portal-card portal-notes__new" wire:submit="sendGeneralNote">
    <h3>{{ __('client_portal.notes.new_general') }}</h3>
    <textarea class="portal-textarea" rows="3" maxlength="2000" wire:model="generalNote" placeholder="{{ __('client_portal.notes.general_placeholder') }}"></textarea>
    @error('generalNote') <p class="portal-field__error">{{ $message }}</p> @enderror
    <div class="portal-notes__send"><button type="submit" class="portal-btn">{{ __('client_portal.notes.send_to', ['company' => $company]) }}</button></div>
</form>

@forelse ($remarks as $remark)
    <div wire:key="notes-tab-{{ $remark->id }}">
        @if ($remark->target !== \App\Enums\ClientRemarkTarget::Audit)
            <div class="portal-meta portal-notes__folder">
                {{ __('client_portal.notes.folder') }}
                <button type="button" class="portal-link" wire:click="{{ $remark->audit_box_id ? 'openBox('.$remark->audit_box_id.')' : 'openPoint('.(int) $remark->audit_control_point_id.')' }}">{{ $remark->target_label }}</button>
            </div>
        @endif
        @include('livewire.client-portal.partials.remark')
    </div>
@empty
    <div class="portal-card portal-empty">{{ __('client_portal.notes.none') }}</div>
@endforelse
