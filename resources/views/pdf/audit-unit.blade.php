<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <link rel="stylesheet" href="{{ resource_path('css/pdf/audit-unit.css') }}">
</head>
<body>
    <table class="pdf-running">
        <tr>
            <td>
                @if ($logo)
                    <img class="pdf-running__logo" src="{{ $logo }}" alt="{{ $company }}">
                @else
                    <span class="pdf-running__company">{{ $company }}</span>
                @endif
            </td>
            <td class="pdf-running__audit">{{ $header }}</td>
        </tr>
    </table>

    <h1 class="pdf-unit__title">{{ $title }}</h1>
    @if ($folder)
        <p class="pdf-unit__folder">{{ $folder }}</p>
    @endif

    <table class="pdf-facts">
        @foreach ($facts as $fact)
            @if ($fact['value'] !== '')
                <tr>
                    <th>{{ $fact['label'] }}</th>
                    <td>
                        @if ($fact['url'])
                            <a href="{{ $fact['url'] }}">{{ $fact['value'] }}</a>
                        @else
                            {{ $fact['value'] }}
                        @endif
                    </td>
                </tr>
            @endif
        @endforeach
    </table>

    @foreach ($sections as $section)
        <div class="pdf-section">{{ $section['title'] }}@if ($section['count'] > 0) ({{ $section['count'] }})@endif</div>
        @if ($section['note'])
            <p class="pdf-note">{{ $section['note'] }}</p>
        @endif
        @if ($section['count'] > 0)
            <table class="pdf-photos">
                @foreach (array_chunk($section['cells'], $columns) as $row)
                    <tr>
                        @foreach ($row as $cell)
                            <td>
                                <div class="pdf-photos__cell">
                                    @if ($uri = \App\Support\PdfImage::dataUri($cell['photo']->file))
                                        <img src="{{ $uri }}" alt="">
                                    @endif
                                </div>
                                <div class="pdf-photos__caption">{{ $cell['caption'] }}</div>
                            </td>
                        @endforeach
                        @for ($filler = count($row); $filler < $columns; $filler++)
                            <td></td>
                        @endfor
                    </tr>
                @endforeach
            </table>
        @endif
    @endforeach

    @if ($report !== [])
        <div class="pdf-section">{{ __('pdf.audit.report') }}</div>
        @foreach ($report as $line)
            <p class="pdf-note">{{ $line }}</p>
        @endforeach
    @endif
</body>
</html>
