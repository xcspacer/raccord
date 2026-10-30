<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <link rel="stylesheet" href="{{ resource_path('css/pdf/dossier-report.css') }}">
</head>
<body>
    <table class="dossier-report__running">
        <tr>
            <td>
                @if ($logo)
                    <img class="dossier-report__logo" src="{{ $logo }}" alt="{{ $company }}">
                @else
                    <span class="dossier-report__company">{{ $company }}</span>
                @endif
            </td>
            <td class="dossier-report__cabinet">{{ $cabinet }}</td>
        </tr>
    </table>

    <h1 class="dossier-report__title">{{ $title }}</h1>

    <table class="dossier-report__facts">
        @foreach ($facts as [$label, $value])
            @if ($value !== '')
                <tr>
                    <th>{{ $label }}</th>
                    <td>{{ $value }}</td>
                </tr>
            @endif
        @endforeach
    </table>

    <h2 class="dossier-report__section">{{ __('design_office_dossier.report.summary') }}</h2>
    @foreach ($summary as $line)
        <p class="dossier-report__text">{{ $line }}</p>
    @endforeach

    <h2 class="dossier-report__section">{{ __('design_office_dossier.report.boxes') }}</h2>
    <table class="dossier-report__table">
        <thead>
            <tr>
                <th>{{ __('design_office_dossier.columns.box') }}</th>
                <th>{{ __('design_office_dossier.columns.kind') }}</th>
                <th>{{ __('design_office_dossier.columns.support') }}</th>
                <th>{{ __('design_office_dossier.columns.conformity') }}</th>
                <th>{{ __('design_office_dossier.columns.reserves') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($boxes as $row)
                <tr>
                    @foreach ($row as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2 class="dossier-report__section">{{ __('design_office_dossier.report.register') }}</h2>
    @if ($register === [])
        <p class="dossier-report__text">{{ __('design_office_dossier.report.none') }}</p>
    @else
        <table class="dossier-report__table">
            <thead>
                <tr>
                    <th>{{ __('design_office_dossier.columns.reference') }}</th>
                    <th>{{ __('design_office_dossier.columns.boxes') }}</th>
                    <th>{{ __('design_office_dossier.columns.nature') }}</th>
                    <th>{{ __('design_office_dossier.columns.state') }}</th>
                    <th>{{ __('design_office_dossier.columns.type') }}</th>
                    <th>{{ __('design_office_dossier.columns.validated') }}</th>
                    <th>{{ __('design_office_dossier.columns.action') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($register as $row)
                    <tr>
                        @foreach ($row as $cell)
                            <td>{{ $cell }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>

        <h2 class="dossier-report__section">{{ __('design_office_dossier.report.sheets') }}</h2>
        @foreach ($sheets as $sheet)
            <div class="dossier-report__sheet">
                <div class="dossier-report__sheet-title">{{ $sheet['title'] }}</div>
                @foreach ($sheet['lines'] as $line)
                    <p class="dossier-report__text">{{ $line }}</p>
                @endforeach
                <table class="dossier-report__photos">
                    <tr>
                        <td class="dossier-report__side">
                            <div class="dossier-report__side-title">{{ __('design_office_dossier.report.before') }}</div>
                            @forelse ($sheet['before'] as $uri)
                                @if ($uri)
                                    <img class="dossier-report__thumbnail" src="{{ $uri }}" alt="">
                                @else
                                    <span class="dossier-report__missing">{{ __('design_office_dossier.report.no_thumbnail') }}</span>
                                @endif
                            @empty
                                <span class="dossier-report__missing">{{ __('design_office_dossier.report.no_photo') }}</span>
                            @endforelse
                        </td>
                        <td class="dossier-report__side">
                            <div class="dossier-report__side-title">{{ __('design_office_dossier.report.after') }}</div>
                            @forelse ($sheet['after'] as $uri)
                                @if ($uri)
                                    <img class="dossier-report__thumbnail" src="{{ $uri }}" alt="">
                                @else
                                    <span class="dossier-report__missing">{{ __('design_office_dossier.report.no_thumbnail') }}</span>
                                @endif
                            @empty
                                <span class="dossier-report__missing">{{ __('design_office_dossier.report.no_photo') }}</span>
                            @endforelse
                        </td>
                    </tr>
                </table>
            </div>
        @endforeach
    @endif

    @if ($reserves !== [])
        <h2 class="dossier-report__section">{{ __('design_office_dossier.report.reserves') }}</h2>
        <table class="dossier-report__table">
            <thead>
                <tr>
                    <th>{{ __('design_office_dossier.columns.reference') }}</th>
                    <th>{{ __('design_office_dossier.columns.box') }}</th>
                    <th>{{ __('design_office_dossier.columns.reserve_kind') }}</th>
                    <th>{{ __('design_office_dossier.columns.justification') }}</th>
                    <th>{{ __('design_office_dossier.columns.accepted') }}</th>
                    <th>{{ __('design_office_dossier.columns.closed') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($reserves as $row)
                    <tr>
                        @foreach ($row as $cell)
                            <td>{{ $cell }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($proofs !== [])
        <h2 class="dossier-report__section">{{ __('design_office_dossier.report.proofs') }}</h2>
        <table class="dossier-report__table">
            <thead>
                <tr>
                    <th>{{ __('design_office_dossier.columns.box') }}</th>
                    <th>{{ __('design_office_dossier.columns.folder') }}</th>
                    <th>{{ __('design_office_dossier.columns.justification') }}</th>
                    <th>{{ __('design_office_dossier.columns.decision') }}</th>
                    <th>{{ __('design_office_dossier.columns.decided_by') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($proofs as $row)
                    <tr>
                        @foreach ($row as $cell)
                            <td>{{ $cell }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2 class="dossier-report__section">{{ __('design_office_dossier.report.decisions') }}</h2>
    @if ($decisions === [])
        <p class="dossier-report__text">{{ __('design_office_dossier.report.none') }}</p>
    @else
        <table class="dossier-report__table">
            <thead>
                <tr>
                    <th>{{ __('design_office_dossier.columns.box') }}</th>
                    <th>{{ __('design_office_dossier.columns.request') }}</th>
                    <th>{{ __('design_office_dossier.columns.decision') }}</th>
                    <th>{{ __('design_office_dossier.columns.decided_by') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($decisions as $row)
                    <tr>
                        @foreach ($row as $cell)
                            <td>{{ $cell }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
