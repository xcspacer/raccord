<div data-camera-config="{{ json_encode($camera, JSON_UNESCAPED_UNICODE) }}" class="inspection" wire:poll.20s.visible>
    @if ($cycle['status'] === 'blocked')
        <div class="field-warning" role="alert">🔒 {{ __('vehicle_inspection.banner_blocked', ['days' => $cycle['days']]) }}</div>
    @elseif ($cycle['status'] === 'due')
        <div class="field-warning inspection__due" role="status">⏰ {{ __('vehicle_inspection.banner_due', ['days' => $cycle['days']]) }}</div>
    @endif

    @foreach ($alerts as $alert)
        <div class="field-warning" role="alert" wire:key="alert-{{ $alert->id }}">
            ⚠️ {{ __('vehicle_inspection.degradation', ['slot' => $alert->slot->label(), 'severity' => $alert->damageSeverity()->label()]) }}
            @if (! empty($alert->comparison['description']))
                — {{ $alert->comparison['description'] }}
            @endif
            <small>{{ __('vehicle_inspection.degradation_hint') }}</small>
        </div>
    @endforeach

    <div class="inspection__grid">
        @foreach ($photoSlots as $slot)
            @php($current = $photos[$slot->value] ?? null)
            <article class="inspection__card" wire:key="slot-{{ $slot->value }}">
                <h3 class="inspection__title">{{ $slot->label() }}</h3>

                @if ($current)
                    <img class="inspection__image" src="{{ route('vehicle-images.show', ['type' => 'photo', 'id' => $current->id, 'variant' => 'thumb']) }}" alt="{{ $slot->label() }}" loading="lazy" data-photo-view="{{ route('vehicle-images.show', ['type' => 'photo', 'id' => $current->id, 'variant' => 'full']) }}">
                    <span @class(['task-badge', 'task-badge--leave' => $current->month === $currentMonth, 'inspection__old' => $current->month !== $currentMonth])>
                        {{ $current->month === $currentMonth ? __('vehicle_inspection.current_month') : __('vehicle_inspection.outdated') }}
                    </span>
                @else
                    <div class="inspection__empty">{{ __('vehicle_inspection.no_photo') }}</div>
                @endif

                <div class="inspection__actions">
                    <button type="button" class="btn btn--ghost" data-geo-camera data-camera-property="photo" data-camera-method="storePhoto" data-camera-args="{{ json_encode([$slot->value]) }}">📷 {{ $current ? __('vehicle_inspection.replace') : __('vehicle_inspection.add') }}</button>
                    @if ($current)
                        <button type="button" class="btn btn--ghost btn--danger" wire:click="removePhoto('{{ $slot->value }}')" wire:confirm="{{ __('vehicle_inspection.confirm_remove') }}">{{ __('vehicle_inspection.remove') }}</button>
                    @endif
                </div>
            </article>
        @endforeach
    </div>
    @error('photo') <p class="field__error">{{ $message }}</p> @enderror
    <x-ai-notice />

    <section class="settings-card inspection__damages">
        <h2 class="settings-card__title">{{ __('vehicle_inspection.damages') }}</h2>

        <div class="field-row">
            <div class="field">
                <label class="field__label" for="damage-type">{{ __('vehicle_inspection.damage_type') }}</label>
                <select class="field__input" id="damage-type" wire:model="damageType">
                    <option value="">—</option>
                    @foreach ($damageTypes as $type)
                        <option value="{{ $type->value }}">{{ $type->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label class="field__label" for="damage-details">{{ __('vehicle_inspection.damage_details') }}</label>
                <input class="field__input" id="damage-details" wire:model="damageDetails" maxlength="500">
            </div>
        </div>
        <div class="form-actions form-actions--left">
            <button type="button" class="btn btn--ghost" data-geo-camera data-camera-property="damagePhoto" data-camera-method="damagePhotoReady">📷 {{ $damagePhoto ? __('vehicle_inspection.replace') : __('vehicle_inspection.damage_photo') }}</button>
            @if ($damagePhoto)
                <span class="file-picker__name">✓ {{ __('vehicle_inspection.photo_ready') }}</span>
            @endif
            <button type="button" class="btn btn--primary" wire:click="addDamage">＋ {{ __('vehicle_inspection.damage_add') }}</button>
        </div>
        @error('damageDetails') <p class="field__error">{{ $message }}</p> @enderror
        @error('damagePhoto') <p class="field__error">{{ $message }}</p> @enderror

        @forelse ($damages as $damage)
            <div class="inspection__damage" wire:key="damage-{{ $damage->id }}">
                @if ($damage->stored_file_id)
                    <img class="inspection__damage-image" src="{{ route('vehicle-images.show', ['type' => 'damage', 'id' => $damage->id, 'variant' => 'thumb']) }}" alt="" loading="lazy">
                @endif
                <div class="inspection__damage-text">
                    <strong>{{ collect([$damage->type?->label(), $damage->details])->filter()->implode(' — ') }}</strong>
                    <small>{{ $damage->occurred_on->translatedFormat('d M Y') }}</small>
                </div>
                <button type="button" class="chip-delete" wire:click="removeDamage({{ $damage->id }})" wire:confirm="{{ __('vehicle_inspection.confirm_remove_damage') }}" aria-label="{{ __('vehicle_inspection.remove') }}">✕</button>
            </div>
        @empty
            <p class="task-empty">{{ __('vehicle_inspection.no_damage') }}</p>
        @endforelse
    </section>
</div>
