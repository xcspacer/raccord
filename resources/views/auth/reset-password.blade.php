<x-layouts.guest :title="__('password_reset.reset_title')">
    <section class="login-card">
        <h2 class="login-card__title">{{ __('password_reset.reset_title') }}</h2>
        <p class="login-card__intro">{{ __('password_reset.reset_intro') }}</p>

        <form method="POST" action="{{ route('password.update') }}" novalidate>
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <div class="field">
                <label class="field__label" for="email">{{ __('password_reset.email') }}</label>
                <input class="field__input" type="email" id="email" name="email" value="{{ old('email', $email) }}" autocomplete="email" autocapitalize="none" spellcheck="false" required>
                @error('email')
                    <p class="field__error" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div class="field">
                <label class="field__label" for="password">{{ __('passwords.new') }}</label>
                <input class="field__input" type="password" id="password" name="password" autocomplete="new-password" required>
                <p class="field__hint">{{ __('password_reset.minimum') }}</p>
                @error('password')
                    <p class="field__error" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div class="field">
                <label class="field__label" for="password_confirmation">{{ __('passwords.confirm') }}</label>
                <input class="field__input" type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" required>
            </div>

            <div class="form-actions form-actions--stretch">
                <button type="submit" class="btn btn--primary btn--block">{{ __('password_reset.reset_submit') }}</button>
            </div>
        </form>

        <p class="login-card__footer">
            <a class="login-card__link" href="{{ route('login') }}">{{ __('password_reset.back_to_login') }}</a>
        </p>
    </section>
</x-layouts.guest>
