@props(['title' => null, 'page' => null, 'focus' => false, 'header' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <x-partials.head-meta :title="$title" />
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body data-user-id="{{ auth()->id() }}" @class(['has-sidenav', 'view-'.$page => $page, 'is-focus' => $focus])>
    <nav class="side-nav" aria-label="{{ __('app.functions') }}">
        <x-navigation.functions-menu :state="$page === 'home'" :groups="$menuGroups" :agencies="$switchableAgencies" :current-agency-id="$currentAgencyId" />
    </nav>

    <div class="app">
        <x-app.header :title="$title" :user="$currentUser" :role="$currentRole" :page="$page">{{ $header }}</x-app.header>

        <x-navigation.view-select :groups="$menuGroups" :page="$page" />

        <main id="content" class="page-in">
            {{ $slot }}
        </main>

        <p class="footer-note">{{ __('app.footer_note') }}</p>
    </div>

    @if ($currentUser?->can('viewAny', \App\Models\Audit::class) && app(\App\Settings\ModuleSettings::class)->isEnabled(\App\Enums\Module::ClientPortal))
        <livewire:client-portal.pending-notes-alert />
    @endif

    @if ($currentRole === \App\Enums\Role::Technician)
        <livewire:day-punch.gate />
        <livewire:day-punch.declare-end-modal />
    @endif

    <x-file-viewer />

    <div class="toast" data-toast role="status" aria-live="polite"></div>
</body>
</html>
