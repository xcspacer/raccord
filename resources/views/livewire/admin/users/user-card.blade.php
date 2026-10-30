<article class="user-card">
    <header class="user-card__head">
        <div>
            <div class="user-card__name">{{ $user->name }}@if ($isSelf) {{ __('users.you') }}@endif</div>
            <div class="user-card__username">{{ '@'.$user->username }}</div>
        </div>

        <div class="user-card__badges">
            @if ($absenceToday)
                <span class="status-badge status-badge--{{ $absenceToday->value }}">{{ __('users.badges.absence_today.'.$absenceToday->value) }}</span>
            @endif
            @if ($isPlanned && $user->is_hidden_from_planning)
                <span class="status-badge status-badge--hidden">🚫 {{ __('users.badges.hidden_from_planning') }}</span>
            @endif
            @if ($showsReviewAvailability && $user->is_available_for_review)
                <span class="status-badge status-badge--leave">{{ __('users.badges.available_for_review') }}</span>
            @endif
            @if ($user->departure_date)
                <span class="status-badge status-badge--hidden">🚪 {{ __($user->hasDeparted() ? 'users.badges.departed_on' : 'users.badges.departs_on', ['date' => $user->departure_date->translatedFormat('d M Y')]) }}</span>
            @endif
        </div>
    </header>

    <div class="user-card__fields">
        <div class="field">
            <label class="field__label" for="role-{{ $user->id }}">{{ __('users.fields.role') }}</label>
            <select class="field__input" id="role-{{ $user->id }}" wire:model.change="role">
                @foreach ($roles as $role)
                    <option value="{{ $role->value }}">{{ $role->label() }}</option>
                @endforeach
            </select>
            @error('role') <p class="field__error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label class="field__label" for="agency-{{ $user->id }}">{{ __('users.fields.agency') }}</label>
            <select class="field__input" id="agency-{{ $user->id }}" wire:model.change="agency">
                <option value="">—</option>
                @foreach ($agencies as $agencyOption)
                    <option value="{{ $agencyOption->id }}">{{ $agencyOption->name }}</option>
                @endforeach
                <option value="all">{{ __('users.all_agencies') }}</option>
            </select>
        </div>

        <div class="field">
            <label class="field__label" for="company-{{ $user->id }}">{{ __('users.fields.company') }}</label>
            <select class="field__input" id="company-{{ $user->id }}" wire:model.change="companyId">
                <option value="">{{ $platformName }}</option>
                @foreach ($companies as $company)
                    <option value="{{ $company->id }}">{{ $company->name }}</option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label class="field__label" for="email-{{ $user->id }}">{{ __('users.fields.email') }}</label>
            <input class="field__input" type="email" id="email-{{ $user->id }}" wire:model.change="email" placeholder="{{ __('users.email_placeholder') }}">
            @error('email') <p class="field__error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label class="field__label" for="phone-{{ $user->id }}">{{ __('users.fields.phone') }}</label>
            <input class="field__input" type="tel" id="phone-{{ $user->id }}" wire:model.change="phone" placeholder="{{ __('users.phone_placeholder') }}">
        </div>

        <div class="field">
            <label class="field__label" for="vehicle-model-{{ $user->id }}">{{ __('users.fields.vehicle_model') }}</label>
            <input class="field__input" id="vehicle-model-{{ $user->id }}" wire:model.change="vehicleModel" placeholder="{{ __('users.vehicle_model_placeholder') }}">
        </div>

        <div class="field">
            <label class="field__label" for="vehicle-plate-{{ $user->id }}">{{ __('users.fields.vehicle_plate') }}</label>
            <input class="field__input" id="vehicle-plate-{{ $user->id }}" wire:model.change="vehiclePlate" placeholder="{{ __('users.vehicle_plate_placeholder') }}">
        </div>

        <div class="field">
            <label class="field__label" for="departure-{{ $user->id }}">{{ __('users.fields.departure_date') }}</label>
            <input class="field__input" type="date" id="departure-{{ $user->id }}" wire:model.change="departureDate" @disabled($isSelf) @if ($isSelf) title="{{ __('users.errors.own_departure') }}" @endif>
            @error('departureDate') <p class="field__error">{{ $message }}</p> @enderror
        </div>
    </div>

    <div class="user-card__save-row">
        <button type="button" class="btn btn--primary btn--small" wire:click="saveAll">{{ __('users.save') }}</button>
    </div>

    @if ($isPlanned && $canManageAbsences)
        <livewire:absences.absence-manager :user="$user" :wire:key="'card-absences-'.$user->id" />
    @endif

    <footer class="user-card__foot">
        <form class="user-card__password" wire:submit="changePassword">
            <input class="field__input" type="password" wire:model="newPassword" placeholder="{{ __('users.new_password') }}" autocomplete="new-password" aria-label="{{ __('users.new_password') }}">
            <button type="submit" class="btn btn--ghost">{{ __('users.change') }}</button>
            @error('newPassword') <p class="field__error">{{ $message }}</p> @enderror
        </form>

        <div class="user-card__actions">
            @unless ($isTechnician)
                <button type="button" class="btn btn--ghost" wire:click="togglePlanned" @if ($user->is_plannable) wire:confirm="{{ __('users.confirm_unplan', ['name' => $user->name]) }}" @endif>
                    {{ $user->is_plannable ? '✕ '.__('users.unplan') : '📅 '.__('users.plan') }}
                </button>
            @endunless

            @if ($showsReviewAvailability)
                <button type="button" class="btn btn--ghost" wire:click="toggleReviewAvailability">
                    {{ $user->is_available_for_review ? '⏸ '.__('users.set_unavailable_for_review') : '▶ '.__('users.set_available_for_review') }}
                </button>
            @endif

            @if ($isPlanned)
                <button type="button" class="btn btn--ghost" wire:click="toggleHiddenFromPlanning">
                    {{ $user->is_hidden_from_planning ? '↩ '.__('users.show_in_planning') : __('users.hide_from_planning') }}
                </button>
            @endif

            @if ($equipmentRoute)
                <a class="btn btn--ghost" href="{{ $equipmentRoute }}" wire:navigate>📦 {{ __('users.equipment') }}</a>
            @endif

            <button type="button" class="btn btn--ghost" wire:click="exportPersonalData" title="{{ __('users.export_data_hint') }}">⬇ {{ __('users.export_data') }}</button>

            @unless ($isSelf)
                <button type="button" class="btn btn--ghost btn--danger" wire:click="delete" wire:confirm="{{ __('users.confirm_delete', ['name' => $user->name]) }}">{{ __('users.delete') }}</button>
            @endunless
            @error('user') <p class="field__error">{{ $message }}</p> @enderror
        </div>
    </footer>

    <livewire:profile.active-sessions :user="$user" :wire:key="'card-sessions-'.$user->id" />

    <x-password-confirmation :open="$pendingConfirmation !== null" :id="'confirm-password-'.$user->id" />
</article>
