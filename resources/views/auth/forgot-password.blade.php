<x-layouts.guest :title="__('password_reset.request_title')">
    <section class="login-card">
        <h2 class="login-card__title">{{ __('password_reset.request_title') }}</h2>
        <p class="login-card__intro">{{ __('password_reset.request_intro') }}</p>

        @if (session('status'))
            <p class="login-card__status" role="status">{{ session('status') }}</p>
        @endif

        <form method="POST" action="{{ route('password.email') }}" novalidate>
            @csrf

            <div class="field">
                <label class="field__label" for="email">{{ __('password_reset.email') }}</label>
                <input class="field__input" type="email" id="email" name="email" value="{{ old('email') }}" autocomplete="email" autocapitalize="none" spellcheck="false" required>
                <p class="field__hint">{{ __('password_reset.no_email_hint') }}</p>
                @error('email')
                    <p class="field__error" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div class="form-actions form-actions--stretch">
                <button type="submit" class="btn btn--primary btn--block">{{ __('password_reset.request_submit') }}</button>
            </div>
        </form>

        <p class="login-card__footer">
            <a class="login-card__link" href="{{ route('login') }}">{{ __('password_reset.back_to_login') }}</a>
        </p>
    </section>
</x-layouts.guest>
