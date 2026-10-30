@inject('company', 'App\Settings\CompanySettings')

<x-layouts.guest :title="__('auth.login_title')">
    <section class="login-card">
        @if ($company->logoUrl())
            <img class="login-card__logo" src="{{ $company->logoUrl() }}" alt="{{ $company->displayName() }}">
        @endif

        <h2 class="login-card__title">{{ __('auth.login_title') }}</h2>

        @if (session('status'))
            <p class="login-card__status" role="status">{{ session('status') }}</p>
        @endif

        <form method="POST" action="{{ route('login') }}" novalidate>
            @csrf

            <div class="field">
                <label class="field__label" for="username">{{ __('auth.username') }}</label>
                <input class="field__input" type="text" id="username" name="username" value="{{ old('username') }}" autocomplete="username" autocapitalize="none" spellcheck="false" required>
            </div>

            <div class="field">
                <label class="field__label" for="password">{{ __('auth.password_label') }}</label>
                <input class="field__input" type="password" id="password" name="password" autocomplete="current-password" required>
            </div>

            @error('username')
                <p class="field__error" role="alert">{{ $message }}</p>
            @enderror

            <div class="form-actions form-actions--stretch">
                <button type="submit" class="btn btn--primary btn--block">{{ __('auth.login_submit') }}</button>
            </div>
        </form>

        <p class="login-card__footer">
            <a class="login-card__link" href="{{ route('password.request') }}">{{ __('password_reset.forgot_link') }}</a>
        </p>
    </section>
</x-layouts.guest>
