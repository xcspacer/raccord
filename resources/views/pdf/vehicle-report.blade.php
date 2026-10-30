<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('pdf.vehicle_report.title') }}</title>
    <link rel="stylesheet" href="{{ resource_path('css/pdf/report.css') }}">
</head>
<body>
    @include('pdf.partials.header', ['title' => __('pdf.vehicle_report.title')])

    <p class="pdf-line"><strong>{{ __('pdf.technician') }}</strong> {{ $technician }}</p>
    <p class="pdf-line"><strong>{{ __('pdf.vehicle_report.vehicle') }}</strong> {{ $vehicle ?: '—' }}</p>

    <table class="pdf-grid">
        @foreach ($slots as $row)
            <tr>
                @foreach ($row as $slot)
                    <td>
                        <div class="pdf-photo">
                            @if ($slot['image'])
                                <img src="{{ $slot['image'] }}" alt="{{ $slot['label'] }}">
                            @else
                                <div class="pdf-photo__empty">{{ __('pdf.vehicle_report.no_photo') }}</div>
                            @endif
                        </div>
                        <div class="pdf-caption">{{ $slot['label'] }}</div>
                    </td>
                @endforeach
                @if ($row->count() < 2)
                    <td></td>
                @endif
            </tr>
        @endforeach
    </table>

    <div class="pdf-section">{{ __('pdf.vehicle_report.damages') }}</div>
    @forelse ($damages as $damage)
        <table class="pdf-damage">
            <tr>
                @if ($damage['image'])
                    <td class="pdf-damage__image"><img src="{{ $damage['image'] }}" alt=""></td>
                @endif
                <td>
                    {{ $damage['label'] ?: '—' }}
                    <div class="pdf-damage__date">{{ $damage['date'] }}</div>
                </td>
            </tr>
        </table>
    @empty
        <p class="pdf-muted">{{ __('pdf.vehicle_report.no_damage') }}</p>
    @endforelse

    <div class="pdf-signature">{{ __('pdf.technician_signature') }}</div>

    @if ($footer)
        <div class="pdf-footer">{{ $footer }}</div>
    @endif
</body>
</html>
