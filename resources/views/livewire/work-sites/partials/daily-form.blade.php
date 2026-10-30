@php($technicianLabel = fn ($technician) => $technician->name.($technician->vehicle?->model ? ' — '.$technician->vehicle->model.($technician->vehicle->plate ? ' - '.$technician->vehicle->plate : '') : ''))

<div class="field-row">
    <div class="field">
        <label class="field__label" for="ws-date">{{ __('work_sites.fields.date') }} *</label>
        <input class="field__input" type="date" id="ws-date" wire:model="daily.date">
        @error('daily.date') <p class="field__error">{{ $message }}</p> @enderror
    </div>
    <div class="field">
        <label class="field__label" for="ws-client">{{ __('work_sites.fields.client') }} *</label>
        <input class="field__input" id="ws-client" list="ws-clients" wire:model="daily.client" placeholder="{{ $defaultClient }}" autocomplete="off">
        <datalist id="ws-clients">
            @foreach ($clients as $name)
                <option value="{{ $name }}"></option>
            @endforeach
        </datalist>
        @error('daily.client') <p class="field__error">{{ $message }}</p> @enderror
    </div>
</div>

<div class="field">
    <label class="field__label" for="ws-lead">{{ __('work_sites.fields.lead') }} *</label>
    <select class="field__input" id="ws-lead" wire:model="daily.lead">
        <option value="">—</option>
        @foreach ($technicians as $technician)
            <option value="{{ $technician->id }}">{{ $technicianLabel($technician) }}</option>
        @endforeach
    </select>
    @error('daily.lead') <p class="field__error">{{ $message }}</p> @enderror
</div>

<div class="field">
    <span class="field__label">{{ __('work_sites.fields.partners') }}</span>
    <div class="repeat-row">
        <select class="field__input" wire:model="daily.partnerToAdd" aria-label="{{ __('work_sites.fields.partners') }}">
            <option value="">{{ __('work_sites.partner_choose') }}</option>
            @foreach ($technicians as $technician)
                @continue((string) $technician->id === $daily->lead || in_array((string) $technician->id, $daily->partners, true))
                <option value="{{ $technician->id }}">{{ $technicianLabel($technician) }}</option>
            @endforeach
        </select>
        <button type="button" class="btn btn--ghost" wire:click="addPartner">+ {{ __('work_sites.add') }}</button>
    </div>
    <div class="chips">
        @forelse ($daily->partners as $partnerId)
            <span class="chip" wire:key="partner-{{ $partnerId }}">
                {{ $technicians->firstWhere('id', (int) $partnerId)?->name }}
                <button type="button" class="chip-delete" wire:click="removePartner('{{ $partnerId }}')" aria-label="{{ __('work_sites.remove') }}">✕</button>
            </span>
        @empty
            <span class="chip-empty">{{ $technicians->isEmpty() ? __('work_sites.no_technician') : __('work_sites.no_partner') }}</span>
        @endforelse
    </div>
</div>

<div class="field-row">
    <div class="field">
        <label class="field__label" for="ws-city">{{ __('work_sites.fields.city') }} *</label>
        <input class="field__input" id="ws-city" wire:model="daily.city" autocomplete="off">
        @error('daily.city') <p class="field__error">{{ $message }}</p> @enderror
    </div>
    <div class="field">
        <label class="field__label" for="ws-cabinet">{{ __($cabinetLabelKey) }}</label>
        <input class="field__input" id="ws-cabinet" wire:model="daily.cabinetRef" autocomplete="off">
    </div>
</div>

<div class="field">
    <label class="field__label" for="ws-box-count">{{ __('work_sites.fields.box_count') }}</label>
    <input class="field__input field__input--narrow" type="number" id="ws-box-count" min="0" max="50" wire:model.live.debounce.400ms="daily.boxCount">
    @error('daily.boxCount') <p class="field__error">{{ $message }}</p> @enderror
</div>

@foreach ($daily->boxes as $index => $box)
    <div class="box-row" wire:key="box-{{ $index }}">
        <div class="field-row">
            <div class="field">
                <label class="field__label" for="ws-box-ref-{{ $index }}">{{ __('work_sites.box_n', ['n' => $index + 1]) }} — {{ __('work_sites.fields.box_reference') }}</label>
                <input class="field__input" id="ws-box-ref-{{ $index }}" wire:model="daily.boxes.{{ $index }}.reference" autocomplete="off">
            </div>
            <div class="field">
                <label class="field__label" for="ws-box-tech-{{ $index }}">{{ __('work_sites.fields.box_technician') }}</label>
                <select class="field__input" id="ws-box-tech-{{ $index }}" wire:model="daily.boxes.{{ $index }}.technicianId">
                    <option value="">—</option>
                    @foreach ($technicians as $technician)
                        <option value="{{ $technician->id }}">{{ $technicianLabel($technician) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field field--narrow">
                <label class="field__label" for="ws-box-pc-{{ $index }}">{{ __('work_sites.fields.box_partner_count') }}</label>
                <input class="field__input" type="number" min="0" max="10" id="ws-box-pc-{{ $index }}" wire:model.live.debounce.400ms="daily.boxes.{{ $index }}.partnerCount">
            </div>
        </div>
        @foreach ($box['partners'] as $partnerIndex => $unused)
            <div class="field" wire:key="box-{{ $index }}-partner-{{ $partnerIndex }}">
                <select class="field__input" wire:model="daily.boxes.{{ $index }}.partners.{{ $partnerIndex }}" aria-label="{{ __('work_sites.fields.box_partner', ['n' => $partnerIndex + 1]) }}">
                    <option value="">{{ __('work_sites.fields.box_partner', ['n' => $partnerIndex + 1]) }}</option>
                    @foreach ($technicians as $technician)
                        <option value="{{ $technician->id }}">{{ $technicianLabel($technician) }}</option>
                    @endforeach
                </select>
            </div>
        @endforeach
    </div>
@endforeach

<div class="field">
    <label class="field__label" for="ws-notes">{{ __('work_sites.fields.notes') }}</label>
    <textarea class="field__input" id="ws-notes" rows="3" wire:model="daily.notes"></textarea>
</div>

@foreach ([['kmz', 'kmzFile', '.kmz,.kml', 'work_sites.fields.kmz'], ['synoptic', 'synopticFile', '.pdf,.png,.jpg,.jpeg,.xlsx,.xls,.csv,.txt', 'work_sites.fields.synoptic']] as [$type, $property, $accept, $labelKey])
    <div class="field">
        <span class="field__label">{{ __($labelKey) }}</span>
        <label class="file-picker">
            <input class="file-picker__input" type="file" wire:model="{{ $property }}" @if ($accept) accept="{{ $accept }}" @endif>
            <span class="btn btn--ghost">{{ __('profile.choose_file') }}</span>
            <span class="file-picker__name">{{ $this->{$property}?->getClientOriginalName() ?? __('profile.no_file') }}</span>
        </label>
        @if ($current = $existing[$type] ?? null)
            <div class="file-existing">{{ __('profile.current_file') }} <a href="{{ route('work-site-attachments.show', $current) }}" target="_blank" rel="noopener">{{ $current->original_name }}</a></div>
        @endif
        @error($property) <p class="field__error">{{ $message }}</p> @enderror
    </div>
@endforeach
