@php
    $key = \Illuminate\Support\Facades\Lang::has('errors.'.$status.'.title') ? (string) $status : 'generic';
@endphp

<x-layouts.guest :title="__('errors.'.$key.'.title')">
    <section class="login-card notice-card" role="alert">
        <h2 class="login-card__title">{{ __('errors.'.$key.'.title') }}</h2>
        <p class="notice-card__message">{{ __('errors.'.$key.'.message') }}</p>
        <p class="login-card__footer"><a class="btn btn--ghost" href="{{ url('/') }}">{{ __('errors.home') }}</a></p>
    </section>
</x-layouts.guest>
