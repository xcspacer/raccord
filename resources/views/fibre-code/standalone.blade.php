@inject('company', 'App\Settings\CompanySettings')
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#1C2226">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="{{ __('fibre_code.app.short_name') }}">
    <title>{{ __('fibre_code.title') }} — {{ $company->displayName() }}</title>

    <link rel="icon" href="{{ asset('icons/icon-192.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="manifest" href="{{ route('fibre-code.app.manifest') }}">

    @fonts
    @vite(['resources/css/fibre-code-standalone.css', 'resources/js/fibre-code/standalone.js'])
    @livewireStyles
</head>
<body class="fibre-code-app" data-service-worker="{{ asset('fibre-code-service-worker.js') }}" data-scope="{{ route('fibre-code.app') }}/">
    @include('fibre-code.partials.converter', ['standalone' => true])

    @livewireScripts
</body>
</html>
