@inject('company', 'App\Settings\CompanySettings')

<x-layouts.portal :title="__('client_portal.title')">
    <section class="portal-login">
        <div class="portal-login__language">
            <x-language-switcher />
        </div>

        <form class="portal-login__card" method="POST" action="{{ route('portal.login.store', $client->portalSlug()) }}" novalidate>
            @csrf

            @include('portal.partials.brand')

            <h1 class="portal-login__title">{{ __('client_portal.title') }}</h1>
            <p class="portal-login__subtitle">{{ $client->portal_subtitle ? __('client_portal.login.subtitle_for', ['project' => $client->portal_subtitle]) : __('client_portal.login.subtitle') }}</p>

            <div class="portal-field">
                <label class="portal-field__label" for="portal-username">{{ __('client_portal.login.username') }}</label>
                <input class="portal-field__input" id="portal-username" name="username" value="{{ old('username') }}" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" required>
            </div>

            <div class="portal-field">
                <label class="portal-field__label" for="portal-password">{{ __('client_portal.login.password') }}</label>
                <input class="portal-field__input" id="portal-password" name="password" type="password" autocomplete="current-password" required>
            </div>

            <button class="portal-btn portal-btn--block" type="submit">{{ __('client_portal.login.submit') }}</button>

            <p class="portal-login__error" role="alert">
                @error('username'){{ $message }}@elseif (session('portal_expired')){{ __('client_portal.login.expired') }}@enderror
            </p>

            <p class="portal-login__legal">{{ __('client_portal.login.legal', ['company' => $company->displayName()]) }}</p>
        </form>
    </section>
</x-layouts.portal>
