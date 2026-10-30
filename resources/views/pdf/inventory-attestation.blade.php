<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('pdf.attestation.title') }}</title>
    <link rel="stylesheet" href="{{ resource_path('css/pdf/report.css') }}">
</head>
<body>
    @include('pdf.partials.header', ['title' => __('pdf.attestation.title'), 'date' => now(config('platform.timezone')), 'logo' => $logo, 'company' => $company])

    <p class="pdf-line"><strong>{{ __('pdf.attestation.reference') }}</strong> {{ $reference }}</p>
    <p class="pdf-line"><strong>{{ __('pdf.attestation.collaborator') }}</strong> {{ $holder->name }}</p>
    @if ($holder->vehicle?->model)
        <p class="pdf-line"><strong>{{ __('pdf.attestation.vehicle') }}</strong> {{ collect([$holder->vehicle->model, $holder->vehicle->plate])->filter()->implode(' - ') }}</p>
    @endif
    <p class="pdf-line"><strong>{{ __('pdf.attestation.delivered_on') }}</strong> {{ $date->translatedFormat('d/m/Y') }}</p>
    <p class="pdf-line"><strong>{{ __('pdf.attestation.representative', ['company' => $company]) }}</strong> {{ $representative }}</p>

    <table class="pdf-expense__table">
        <thead>
            <tr>
                <th>{{ __('pdf.attestation.designation') }}</th>
                <th>{{ __('pdf.attestation.brand_model') }}</th>
                <th class="pdf-expense__col-nature">{{ __('pdf.attestation.serial') }}</th>
                <th class="pdf-attestation__col-quantity">{{ __('pdf.attestation.quantity') }}</th>
                <th class="pdf-expense__col-date">{{ __('pdf.attestation.condition') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($items as $item)
                <tr>
                    <td>{{ $item->label }}</td>
                    <td>{{ collect([$item->brand, $item->model])->filter()->implode(' / ') }}</td>
                    <td>{{ $item->serial }}</td>
                    <td class="pdf-expense__amount">{{ $item->quantity }}</td>
                    <td>{{ $item->condition->label() }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($observations)
        <div class="pdf-section">{{ __('pdf.attestation.observations') }}</div>
        <p class="pdf-line">{{ $observations }}</p>
    @endif

    <p class="pdf-attestation__legal">{{ __('pdf.attestation.legal', ['company' => $company]) }}</p>

    <table class="pdf-attestation__signatures">
        <tr>
            <td>
                <div class="pdf-attestation__box">@if ($technicianSignatureUri)<img src="{{ $technicianSignatureUri }}" alt="">@endif</div>
                <div class="pdf-caption">{{ __('pdf.attestation.collaborator_signature') }}<br>{{ $holder->name }}</div>
            </td>
            <td>
                <div class="pdf-attestation__box">@if ($representativeSignatureUri)<img src="{{ $representativeSignatureUri }}" alt="">@endif</div>
                <div class="pdf-caption">{{ __('pdf.attestation.representative_signature', ['company' => $company]) }}<br>{{ $representative }}</div>
            </td>
        </tr>
    </table>

    @unless ($technicianSignatureUri && $representativeSignatureUri)
        <p class="pdf-attestation__unsigned">{{ __('pdf.attestation.unsigned') }}</p>
    @endunless

    @if ($footer)
        <div class="pdf-footer">{{ $footer }}</div>
    @endif
</body>
</html>
