<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <x-partials.head-meta :title="$title ?? null" />
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="guest">
    <div class="guest__language">
        <x-language-switcher />
    </div>

    <main class="guest__screen">
        {{ $slot }}
    </main>
</body>
</html>
