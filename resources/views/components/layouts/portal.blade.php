@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <x-partials.head-meta :title="$title" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="portal">
    {{ $slot }}

    <div class="toast" data-toast role="status" aria-live="polite"></div>
</body>
</html>
