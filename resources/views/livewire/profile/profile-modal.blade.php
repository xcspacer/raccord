<div>
    <button type="button" class="btn-edit-profile" wire:click="openModal" title="{{ __('profile.edit') }}" aria-label="{{ __('profile.edit') }}">✎</button>

    @if ($open)
        <div class="overlay" wire:click.self="close" wire:keydown.escape="close">
            <div class="modal" role="dialog" aria-modal="true" aria-labelledby="profile-title">
                <h2 id="profile-title">{{ __('profile.title') }}</h2>

                <form wire:submit="save" novalidate>
                    <div class="field">
                        <label class="field__label" for="profile-email">{{ __('profile.fields.email') }}</label>
                        <input class="field__input" type="email" id="profile-email" wire:model="email" autocomplete="email">
                        @error('email') <p class="field__error">{{ $message }}</p> @enderror
                    </div>

                    <div class="field">
                        <label class="field__label" for="profile-phone">{{ __('profile.fields.phone') }}</label>
                        <input class="field__input" type="tel" id="profile-phone" wire:model="phone" autocomplete="tel">
                        @error('phone') <p class="field__error">{{ $message }}</p> @enderror
                    </div>

                    <div class="field">
                        <label class="field__label" for="profile-new-password">{{ __('profile.fields.new_password') }}</label>
                        <input class="field__input" type="password" id="profile-new-password" wire:model="newPassword" autocomplete="new-password">
                        @error('newPassword') <p class="field__error">{{ $message }}</p> @enderror
                    </div>

                    @if ($newPassword !== '')
                        <div class="field">
                            <label class="field__label" for="profile-current-password">{{ __('profile.fields.current_password') }}</label>
                            <input class="field__input" type="password" id="profile-current-password" wire:model="currentPassword" autocomplete="current-password">
                            @error('currentPassword') <p class="field__error">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    @foreach ($documentTypes as $type)
                        @php($property = $type === \App\Enums\UserDocumentType::BtpCard ? 'btpCard' : 'drivingLicence')
                        @php($existing = $documents[$type->value] ?? null)
                        <div class="field">
                            <span class="field__label">{{ $type->label() }}</span>
                            <label class="file-picker">
                                <input class="file-picker__input" type="file" wire:model="{{ $property }}" accept=".pdf,.png,.jpg,.jpeg">
                                <span class="btn btn--ghost">{{ __('profile.choose_file') }}</span>
                                <span class="file-picker__name">{{ $this->{$property}?->getClientOriginalName() ?? __('profile.no_file') }}</span>
                            </label>
                            @if ($existing)
                                <div class="file-existing">{{ __('profile.current_file') }} {{ $existing->original_name }} — <button type="button" class="file-open-link" data-file-view-open data-file-url="{{ route('user-documents.show', $existing) }}" data-file-name="{{ $existing->original_name }}">{{ __('file_viewer.view') }}</button> · <a href="{{ route('user-documents.show', $existing) }}" download="{{ $existing->original_name }}">{{ __('file_viewer.download') }}</a></div>
                            @endif
                            @error($property) <p class="field__error">{{ $message }}</p> @enderror
                        </div>
                    @endforeach

                    <section class="privacy-notice" aria-labelledby="profile-privacy-title">
                        <h3 class="privacy-notice__title" id="profile-privacy-title">{{ __('profile.privacy.title') }}</h3>
                        @if ($recordsLocation)
                            <p>{{ __('profile.privacy.location', ['months' => config('platform.location_retention_months')]) }}</p>
                        @endif
                        @if ($usesAi)
                            <p>{{ __('profile.privacy.ai') }}</p>
                        @endif
                        @if ($sharedWithClients)
                            <p>{{ __('profile.privacy.portal') }}</p>
                        @endif
                        <p>{{ __('profile.privacy.copy') }}</p>
                    </section>

                    <livewire:profile.active-sessions :user="auth()->user()" wire:key="profile-sessions" />

                    <div class="modal-actions">
                        <button type="button" class="btn btn--ghost" wire:click="close">{{ __('profile.close') }}</button>
                        <button type="submit" class="btn btn--primary" wire:loading.attr="disabled" wire:target="save,btpCard,drivingLicence">{{ __('profile.save') }}</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
