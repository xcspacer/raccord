<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $body }}</title>
</head>
<body>
    <p>{{ $greeting }}</p>
    <p>{{ $body }}</p>
    <p>{{ $hint }}</p>
    <p><small>{{ $reason }}</small></p>
</body>
</html>
