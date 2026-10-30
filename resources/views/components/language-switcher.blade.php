@php
    $current = app()->getLocale();
@endphp

<details {{ $attributes->class('lang-dropdown') }}>
    <summary class="lang-dropdown__button" title="{{ __('platform.language') }}" aria-label="{{ __('platform.language') }}">
        <span class="flag-icon flag-icon--{{ $current }}"></span>
    </summary>

    <div class="lang-dropdown__menu">
        @foreach (config('app.supported_locales') as $locale)
            <form method="POST" action="{{ route('locale.update') }}">
                @csrf
                <input type="hidden" name="locale" value="{{ $locale }}">
                <button type="submit" @class(['lang-dropdown__option', 'is-active' => $locale === $current]) lang="{{ str_replace('_', '-', $locale) }}">
                    <span class="flag-icon flag-icon--{{ $locale }}"></span>
                    {{ __('platform.languages.'.$locale) }}
                </button>
            </form>
        @endforeach
    </div>
</details>
