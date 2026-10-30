<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <x-partials.head-meta :title="$title" />
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="plan-body">
    {{-- Alpine runs in its CSP-safe build: the logic lives in resources/js/audit/plan-viewer.js. --}}
    <div class="plan" x-data="auditPlan" data-config="{{ json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}" x-cloak>
        <header class="plan__top">
            <a class="btn btn--ghost" x-bind:href="backUrl" x-text="backText"></a>
            <strong class="plan__title" x-text="title"></strong>
            <input class="plan__search" type="search" x-model="query" x-on:input="highlight" x-bind:placeholder="t('search')" x-bind:aria-label="t('search')">
            <button type="button" class="btn btn--ghost" x-on:click="zoomOut" x-bind:aria-label="t('zoom_out')">−</button>
            <button type="button" class="btn btn--ghost" x-on:click="zoomIn" x-bind:aria-label="t('zoom_in')">+</button>
            <a class="btn btn--primary" x-bind:href="downloadUrl" x-text="downloadText"></a>
        </header>

        <p class="plan__message" x-show="message !== ''" x-text="message"></p>
        <div class="plan__sheet" x-ref="sheet"></div>

        <nav class="plan__tabs" x-show="sheets.length > 1" aria-label="{{ __('audit.plan.sheets') }}">
            <template x-for="(sheet, index) in sheets" x-bind:key="sheet.name">
                <button type="button" class="plan__tab" x-bind:class="tabClass(index)" x-on:click="select(index)" x-text="sheet.name"></button>
            </template>
        </nav>
    </div>
    @livewireScripts
</body>
</html>
