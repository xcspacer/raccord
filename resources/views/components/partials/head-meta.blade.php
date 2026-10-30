@props(['title' => null])
@inject('company', 'App\Settings\CompanySettings')

<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#0E2836">
<title>{{ $title ? $title.' — '.$company->displayName() : $company->displayName() }}</title>

<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="48x48">
<link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
<link rel="manifest" href="{{ route('web-manifest') }}">
<meta name="apple-mobile-web-app-title" content="{{ $company->displayName() }}">
<meta name="expired-page-question" content="{{ __('errors.419.reload_question') }}">
<meta name="empty-response-question" content="{{ __('errors.empty_response_question') }}">

<meta property="og:type" content="website">
<meta property="og:title" content="{{ $company->displayName() }}">
<meta property="og:image" content="{{ asset('og-image.png') }}">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
