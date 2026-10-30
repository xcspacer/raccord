@php($number = \App\Livewire\Audit\BoxRecheckPage::number((string) $code))
@php($colour = $number > 0 ? $palette[($number - 1) % 12] : null)
<span @class(['recheck__chip-colour', 'is-dark' => $colour && $colour['dark'], 'recheck-colour-'.($number > 0 ? (($number - 1) % 12) + 1 : 0) => true])>{{ $code }}</span>
