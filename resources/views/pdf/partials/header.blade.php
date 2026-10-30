<table class="pdf-header">
    <tr>
        <td>
            @if ($logo)
                <img @class(['pdf-header__logo', 'pdf-header__logo--wide' => ($wideLogo ?? null) === true, 'pdf-header__logo--tall' => ($wideLogo ?? null) === false]) src="{{ $logo }}" alt="{{ $company }}">
            @else
                <span class="pdf-header__company">{{ $company }}</span>
            @endif
        </td>
        <td>
            <div class="pdf-header__title">{{ $title }}</div>
            <div class="pdf-header__date">{{ __('pdf.drawn_up_on', ['date' => $date->translatedFormat('d/m/Y')]) }}</div>
        </td>
    </tr>
</table>
