<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('pdf.expense_report.title') }}</title>
    <link rel="stylesheet" href="{{ resource_path('css/pdf/expense-report.css') }}">
</head>
<body class="expense-pdf">
    <table class="expense-pdf__head">
        <tr>
            <td class="expense-pdf__brand" colspan="3" rowspan="3">
                @if ($logo)
                    <img @class(['expense-pdf__logo', 'expense-pdf__logo--wide' => $wideLogo]) src="{{ $logo }}" alt="{{ $company }}">
                @else
                    <span class="expense-pdf__company">{{ $company }}</span>
                @endif
            </td>
            <td class="expense-pdf__head-top"></td>
        </tr>
        <tr><td class="expense-pdf__month">{{ $monthLabel }}</td></tr>
        <tr><td class="expense-pdf__year">{{ $year }}</td></tr>
        <tr>
            <td class="expense-pdf__name-label">{{ __('pdf.expense_report.name') }}</td>
            <td class="expense-pdf__name">{{ $name }}</td>
            <td></td>
            <td class="expense-pdf__edge"></td>
        </tr>
        <tr class="expense-pdf__head-gap"><td colspan="3"></td><td class="expense-pdf__edge"></td></tr>
    </table>

    <table class="expense-pdf__lines expense-pdf__lines--fit-{{ $fit }}">
        <thead>
            <tr>
                <th class="expense-pdf__col-date">{{ __('pdf.expense_report.date') }}</th>
                <th class="expense-pdf__col-nature">{{ __('pdf.expense_report.nature') }}</th>
                <th class="expense-pdf__col-comment">{{ __('pdf.expense_report.comment') }}</th>
                <th class="expense-pdf__col-amount">{{ __('pdf.expense_report.amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr @class(['expense-pdf__row--double' => $row['double']])>
                    <td class="expense-pdf__center">{{ $row['line']?->spent_on?->translatedFormat('d/m/Y') }}</td>
                    <td class="expense-pdf__center">{{ $row['line']?->nature->label() }}</td>
                    <td>@foreach ($row['comment'] as $commentLine){{ $commentLine }}@unless ($loop->last)<br>@endunless @endforeach</td>
                    <td class="expense-pdf__amount">{{ $row['line'] && $row['line']->amount_cents !== 0 ? \App\Support\Money::format($row['line']->amount_cents) : '' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="expense-pdf__summary">
        <tr>
            <th colspan="2">{{ __('pdf.expense_report.total') }} <span class="expense-pdf__automatic">{{ __('pdf.expense_report.automatic') }}</span></th>
            <th class="expense-pdf__recap-head" colspan="2">{{ __('pdf.expense_report.recap') }} <span class="expense-pdf__automatic">{{ __('pdf.expense_report.automatic') }}</span></th>
        </tr>
        @foreach ($recap as $group => $cents)
            <tr @class(['expense-pdf__recap-row--double' => $group === 'transport'])>
                @if ($loop->first)
                    <td class="expense-pdf__total-label" rowspan="2">{{ __('expense_reports.total') }}</td>
                    <td class="expense-pdf__total-amount expense-pdf__amount" rowspan="2">{{ \App\Support\Money::format($total) }}</td>
                @elseif ($loop->index > 1)
                    <td colspan="2"></td>
                @endif
                <td class="expense-pdf__recap-label">{{ __('expense_reports.recap.'.$group) }}</td>
                <td class="expense-pdf__recap-amount expense-pdf__amount">{{ $cents === 0 ? __('pdf.expense_report.no_amount') : \App\Support\Money::format($cents) }}</td>
            </tr>
        @endforeach
        <tr class="expense-pdf__recap-total">
            <td colspan="2"></td>
            <td class="expense-pdf__recap-label">{{ __('pdf.expense_report.grand_total') }}</td>
            <td class="expense-pdf__recap-amount expense-pdf__amount">{{ \App\Support\Money::format($total) }}</td>
        </tr>
    </table>

    @if ($footer)
        <div class="expense-pdf__footer">{{ $footer }}</div>
    @endif

    @if ($photos->isNotEmpty())
        <table class="expense-pdf__proofs expense-pdf__proofs--rows-{{ $grid['rows'] }}">
            <tr><th colspan="{{ $grid['columns'] }}">{{ __('pdf.expense_report.proofs', ['name' => $name, 'month' => $monthLabel.' '.$year]) }}</th></tr>
            @foreach ($photos->chunk($grid['columns']) as $chunk)
                <tr>
                    @foreach ($chunk as $photo)
                        <td><img src="{{ $photo }}" alt=""></td>
                    @endforeach
                    @for ($i = $chunk->count(); $i < $grid['columns']; $i++)
                        <td></td>
                    @endfor
                </tr>
            @endforeach
        </table>
    @endif
</body>
</html>
