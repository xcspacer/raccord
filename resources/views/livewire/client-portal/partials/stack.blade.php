@php($segment = $charts->stack($bucket))
<svg class="portal-stack" viewBox="0 0 100 14" preserveAspectRatio="none" aria-hidden="true">
    @foreach ($segment as $part)
        <rect class="portal-fill portal-fill--{{ $part['status'] }}" x="{{ $part['x'] }}" y="0" width="{{ $part['width'] }}" height="14"><title>{{ __('client_portal.status.'.$part['status']) }} : {{ $part['count'] }}</title></rect>
    @endforeach
</svg>
