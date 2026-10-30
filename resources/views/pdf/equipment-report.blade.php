<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('pdf.equipment_report.title') }}</title>
    <link rel="stylesheet" href="{{ resource_path('css/pdf/report.css') }}">
</head>
<body>
    @include('pdf.partials.header', ['title' => __('pdf.equipment_report.title')])

    <p class="pdf-line"><strong>{{ __('pdf.technician') }}</strong> {{ $technician }}</p>
    @foreach ($assigned as $line)
        <p class="pdf-line"><strong>{{ $line['label'] }}</strong> {{ $line['value'] ?: '—' }}</p>
    @endforeach

    <div class="pdf-section">{{ __('pdf.equipment_report.registered') }}</div>
    @forelse ($items as $item)
        <table class="pdf-damage">
            <tr>
                <td class="pdf-damage__image">
                    @if ($item['image'])
                        <img src="{{ $item['image'] }}" alt="">
                    @endif
                </td>
                <td>
                    <strong>{{ $item['type'] }}</strong>
                    <div>{{ __('pdf.equipment_report.brand') }} {{ $item['brand'] ?: '—' }} &nbsp; {{ __('pdf.equipment_report.model') }} {{ $item['model'] ?: '—' }}</div>
                    <div>{{ __('pdf.equipment_report.serial_number') }} {{ $item['serial_number'] ?: '—' }}</div>
                </td>
            </tr>
        </table>
    @empty
        <p class="pdf-muted">{{ __('pdf.equipment_report.none') }}</p>
    @endforelse

    <div class="pdf-signature">{{ __('pdf.technician_signature') }}</div>

    @if ($footer)
        <div class="pdf-footer">{{ $footer }}</div>
    @endif
</body>
</html>
