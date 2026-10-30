<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('pdf.inventory.title') }}</title>
    <link rel="stylesheet" href="{{ resource_path('css/pdf/report.css') }}">
</head>
<body>
    @include('pdf.partials.header', ['title' => $holder ? __('pdf.inventory.title_for', ['name' => $holder->name]) : __('pdf.inventory.title')])

    <p class="pdf-line">{{ __('pdf.inventory.summary', ['date' => $date->translatedFormat('d/m/Y'), 'count' => $items->count()]) }}</p>

    <table class="pdf-expense__table">
        <thead>
            <tr>
                <th class="pdf-attestation__col-quantity">{{ __('inventory.export.columns.id') }}</th>
                <th>{{ __('inventory.export.columns.label') }}</th>
                <th>{{ __('inventory.export.columns.brand') }} / {{ __('inventory.export.columns.model') }}</th>
                <th class="pdf-expense__col-nature">{{ __('inventory.export.columns.serial') }}</th>
                <th class="pdf-attestation__col-quantity">{{ __('inventory.export.columns.quantity') }}</th>
                <th class="pdf-expense__col-date">{{ __('inventory.export.columns.status') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($items as $item)
                <tr>
                    <td>{{ $item->reference() }}</td>
                    <td>{{ $item->label }}@if (! $holder && $item->holder) ({{ $item->holder->name }})@endif</td>
                    <td>{{ collect([$item->brand, $item->model])->filter()->implode(' / ') }}</td>
                    <td>{{ $item->serial }}</td>
                    <td class="pdf-expense__amount">{{ $item->quantity }}</td>
                    <td>{{ $item->status->label() }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($footer)
        <div class="pdf-footer">{{ $footer }}</div>
    @endif
</body>
</html>
