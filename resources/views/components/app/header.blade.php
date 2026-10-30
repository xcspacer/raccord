@props(['title', 'user', 'role', 'page' => null])
@inject('company', 'App\Settings\CompanySettings')

<div class="sticky-header-zone">
    <header class="app-header">
        <div class="app-header__actions">
            <x-language-switcher />

            <button type="button" class="icon-btn icon-btn--refresh" data-action="refresh" title="{{ __('app.refresh') }}" aria-label="{{ __('app.refresh') }}">⟳</button>

            <form method="POST" action="{{ route('logout') }}" data-pending-confirm="{{ __('app.logout_pending') }}">
                @csrf
                <button type="submit" class="icon-btn icon-btn--power" title="{{ __('app.logout') }}" aria-label="{{ __('app.logout') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v8M6.3 6.8a8 8 0 1 0 11.4 0"/></svg></button>
            </form>
        </div>

        <div class="title-row">
            <div class="title-block">
                <h1 class="page-title">{{ $title }}</h1>
            </div>

            @if ($company->logoUrl())
                <div class="logo-chip">
                    <img src="{{ $company->logoUrl() }}" alt="{{ $company->displayName() }}">
                </div>
            @endif
        </div>

        {{ $slot }}

        <div class="user-bar">
            @if ($role === \App\Enums\Role::Technician)
                <livewire:day-punch.end-button />
            @endif

            @if ($role !== \App\Enums\Role::DesignOffice && $page !== 'home')
                <a @class(['btn', 'btn--ghost', 'is-active' => $page === 'home']) href="{{ route('home') }}" wire:navigate>{{ __('app.home') }}</a>
            @endif

            <span class="user-bar__who">
                <livewire:profile.profile-modal />
                <span>{{ $user->name }}</span>
                @if ($role)
                    <span class="role-pill role-pill--{{ $role->value }}">{{ $role->label() }}</span>
                @endif
            </span>
        </div>
    </header>
</div>
