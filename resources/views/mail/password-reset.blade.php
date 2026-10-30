<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $action }}</title>
</head>
<body>
    <p>{{ $greeting }}</p>
    <p>{{ $body }}</p>
    <p><a href="{{ $url }}">{{ $action }}</a></p>
    <p>{{ $expiry }}</p>
    <p><small>{{ $ignore }}</small></p>
</body>
</html>
