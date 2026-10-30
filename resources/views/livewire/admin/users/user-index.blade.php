<div class="users-page">
    <details class="add-user" @if ($errors->any()) open @endif>
        <summary class="add-user__summary">+ {{ __('users.add') }}</summary>

        <form wire:submit="create" class="add-user__form" novalidate>
            <div class="field-row">
                <div class="field">
                    <label class="field__label" for="new-name">{{ __('users.fields.name') }} *</label>
                    <input class="field__input" id="new-name" wire:model="form.name" autocomplete="off">
                    @error('form.name') <p class="field__error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label class="field__label" for="new-username">{{ __('users.fields.username') }} *</label>
                    <input class="field__input" id="new-username" wire:model="form.username" autocomplete="off" autocapitalize="none" spellcheck="false">
                    @error('form.username') <p class="field__error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="field-row">
                <div class="field">
                    <label class="field__label" for="new-role">{{ __('users.fields.role') }}</label>
                    <select class="field__input" id="new-role" wire:model.live="form.role">
                        @foreach ($roles as $role)
                            <option value="{{ $role->value }}">{{ $role->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label class="field__label" for="new-agency">{{ __('users.fields.agency') }}</label>
                    <select class="field__input" id="new-agency" wire:model="form.agencyId">
                        <option value="">—</option>
                        @foreach ($agencies as $agency)
                            <option value="{{ $agency->id }}">{{ $agency->name }}</option>
                        @endforeach
                    </select>
                    @error('form.agencyId') <p class="field__error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="field">
                <label class="field__label" for="new-vehicle">{{ __('users.fields.vehicle_optional') }}</label>
                <input class="field__input" id="new-vehicle" wire:model="form.vehicle" placeholder="{{ __('users.vehicle_placeholder') }}">
            </div>

            <div class="field-row">
                <div class="field">
                    <label class="field__label" for="new-email">{{ __('users.fields.email_optional') }}</label>
                    <input class="field__input" type="email" id="new-email" wire:model="form.email">
                    @error('form.email') <p class="field__error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label class="field__label" for="new-phone">{{ __('users.fields.phone_optional') }}</label>
                    <input class="field__input" type="tel" id="new-phone" wire:model="form.phone">
                </div>
            </div>

            <div class="field">
                <label class="field__label" for="new-password">{{ __('users.fields.temporary_password') }}</label>
                <input class="field__input" type="password" id="new-password" wire:model="form.password" autocomplete="new-password">
                <p class="field__hint">{{ __('users.temporary_password_hint') }}</p>
                @error('form.password') <p class="field__error">{{ $message }}</p> @enderror
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn--primary">{{ __('users.add_submit') }}</button>
            </div>
        </form>
    </details>

    <p class="page-hint">{{ __('users.hint') }}</p>

    <input class="search-input" type="search" wire:model.live.debounce.250ms="search" placeholder="{{ __('users.search') }}" aria-label="{{ __('users.search') }}">

    <p class="users-page__count">{{ trans_choice('users.count', $users->count(), ['count' => $users->count()]) }}</p>

    <div class="users-grid">
        @forelse ($users as $user)
            <livewire:admin.users.user-card :user="$user" :wire:key="'user-'.$user->id" />
        @empty
            <div class="empty-state">{{ __('users.no_match', ['query' => $search]) }}</div>
        @endforelse
    </div>
</div>
