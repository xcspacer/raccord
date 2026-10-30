<x-layouts.portal :title="__('client_portal.password_change.title')">
    <section class="portal-login">
        <div class="portal-login__language">
            <x-language-switcher />
        </div>

        <div class="portal-login__card">
            <form method="POST" action="{{ route('portal.password.update', $client->portalSlug()) }}" novalidate>
                @csrf
                @method('PUT')

                @include('portal.partials.brand')

                <h1 class="portal-login__title">{{ __('client_portal.password_change.title') }}</h1>
                <p class="portal-login__subtitle">{{ __('client_portal.password_change.intro') }}</p>

                <div class="portal-field">
                    <label class="portal-field__label" for="portal-current-password">{{ __('client_portal.password_change.current') }}</label>
                    <input class="portal-field__input" id="portal-current-password" name="current_password" type="password" autocomplete="current-password" required>
                    @error('current_password') <p class="portal-field__error" role="alert">{{ $message }}</p> @enderror
                </div>

                <div class="portal-field">
                    <label class="portal-field__label" for="portal-new-password">{{ __('client_portal.password_change.new') }}</label>
                    <input class="portal-field__input" id="portal-new-password" name="password" type="password" autocomplete="new-password" required>
                    <p class="portal-field__hint">{{ __('client_portal.password_change.minimum', ['count' => $minimumLength]) }}</p>
                    @error('password') <p class="portal-field__error" role="alert">{{ $message }}</p> @enderror
                </div>

                <div class="portal-field">
                    <label class="portal-field__label" for="portal-confirm-password">{{ __('client_portal.password_change.confirm') }}</label>
                    <input class="portal-field__input" id="portal-confirm-password" name="password_confirmation" type="password" autocomplete="new-password" required>
                </div>

                <button class="portal-btn portal-btn--block" type="submit">{{ __('client_portal.password_change.save') }}</button>
            </form>

            <form class="portal-login__footer" method="POST" action="{{ route('portal.logout', $client->portalSlug()) }}">
                @csrf
                <button type="submit" class="portal-btn portal-btn--ghost portal-btn--small">{{ __('client_portal.password_change.logout') }}</button>
            </form>
        </div>
    </section>
</x-layouts.portal>
