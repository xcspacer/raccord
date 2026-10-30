<x-layouts.guest :title="__('passwords.title')">
    <section class="login-card">
        <h2 class="login-card__title">{{ __('passwords.title') }}</h2>
        <p class="login-card__intro">{{ __('passwords.intro') }}</p>

        <form method="POST" action="{{ route('user-password.update') }}" novalidate>
            @csrf
            @method('PUT')

            <div class="field">
                <label class="field__label" for="current_password">{{ __('passwords.current') }}</label>
                <input class="field__input" type="password" id="current_password" name="current_password" autocomplete="current-password" required>
                @error('current_password', 'updatePassword')
                    <p class="field__error" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div class="field">
                <label class="field__label" for="password">{{ __('passwords.new') }}</label>
                <input class="field__input" type="password" id="password" name="password" autocomplete="new-password" required>
                <p class="field__hint">{{ __('passwords.minimum', ['count' => $minimumLength]) }}</p>
                @error('password', 'updatePassword')
                    <p class="field__error" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div class="field">
                <label class="field__label" for="password_confirmation">{{ __('passwords.confirm') }}</label>
                <input class="field__input" type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" required>
            </div>

            <div class="form-actions form-actions--stretch">
                <button type="submit" class="btn btn--primary btn--block">{{ __('passwords.save') }}</button>
            </div>
        </form>

        <form method="POST" action="{{ route('logout') }}" class="login-card__footer">
            @csrf
            <button type="submit" class="btn btn--ghost">{{ __('passwords.logout') }}</button>
        </form>
    </section>
</x-layouts.guest>
