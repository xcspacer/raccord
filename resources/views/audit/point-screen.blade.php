<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <x-partials.head-meta :title="__('audit.field.title')" />
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="field-body" data-user-id="{{ auth()->id() }}">
    {{-- Alpine runs in its CSP-safe build: expressions stay simple (properties, calls, !, &&), the logic lives in resources/js/audit/box-screen.js. --}}
    <div class="site-screen" data-audit-point x-data="auditPoint" data-config="{{ json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}" x-cloak>
        <header class="site-screen__top">
            <button type="button" class="btn btn--ghost" x-on:click="leave" x-text="leaveText"></button>
            <span class="site-screen__chip" x-bind:class="chipClass" x-text="chipText" x-on:click="openRefused"></span>
        </header>

        <div class="field-warning" role="alert" x-show="memoryOnly" x-text="memoryText"></div>
        <div class="field-warning" role="alert" x-show="stalePhotos" x-text="stalePhotosText"></div>
        <div class="field-warning" role="alert" x-show="updateProblem" x-text="updateText"></div>
        <div class="field-warning" role="alert" x-show="authProblem">
            <span x-text="authText"></span> <a class="btn btn--ghost" x-bind:href="loginUrl" x-text="signInText"></a>
        </div>

        <p x-show="!ready" x-text="loadingText"></p>
        <p x-show="ready && !view" x-text="offlineText"></p>
        <p x-show="ready && view && !point" x-text="t('point_unavailable')"></p>

        <template x-if="point">
            <main class="site-screen__box">
                <div class="site-screen__head">
                    <h1 class="site-screen__name" x-text="pointTitle"></h1>
                </div>

                @include('audit.partials.client-notes')

                <button type="button" class="site-screen__shot" x-show="navigable" x-on:click="openNavigation" x-text="navigateText"></button>
                <div class="site-screen__tools" x-show="drawerTools">
                    <a class="btn btn--ghost" x-show="drawerRecheckUrl" x-bind:href="drawerRecheckUrl" x-text="drawerRecheckText"></a>
                    <a class="btn btn--ghost" x-show="drawerAfterWorksUrl" x-bind:href="drawerAfterWorksUrl" download x-text="drawerAfterWorksText"></a>
                </div>

                <template x-for="section in sections" x-bind:key="section.key">
                    <section class="site-screen__block">
                        <h2 class="site-screen__label" x-text="section.title"></h2>
                        <button type="button" class="site-screen__shot" x-bind:disabled="blocked" x-on:click="takePhoto(section.key)" x-text="shotText"></button>
                        @include('audit.partials.photos', ['photos' => 'section.photos'])
                    </section>
                </template>

                <section class="site-screen__block">
                    <h2 class="site-screen__label" x-text="t('point_note')"></h2>
                    <textarea class="site-screen__input" rows="3" maxlength="2000" x-model="drafts.note" x-bind:disabled="blocked" x-on:change="saveNote"></textarea>
                </section>

                <section class="site-screen__block" x-show="reportShown">
                    <button type="button" class="site-screen__shot" x-bind:disabled="blocked" x-on:click="openReport" x-text="reportButtonText"></button>
                </section>

                <section class="site-screen__block" x-show="manager">
                    <button type="button" class="btn btn--ghost" x-on:click="askPdf" x-text="pdfCreateText"></button>
                    <a class="btn btn--ghost" x-show="pdf" x-bind:href="pdfUrl" target="_blank" rel="noopener" x-text="pdfOpenText"></a>
                </section>

                <section class="site-screen__block">
                    <button type="button" class="btn btn--ghost" x-on:click="openLocalPhotos" x-text="localPhotosText"></button>
                </section>

                <footer class="site-screen__footer">
                    <button type="button" class="btn btn--primary" x-on:click="leave" x-text="leaveText"></button>
                </footer>
            </main>
        </template>

        <div class="site-screen__overlay" x-show="sheetOpen" x-on:click.self="closeSheet" x-on:keydown.escape.window="closeSheet">
            <div class="site-screen__sheet" role="dialog" aria-modal="true">
                <template x-if="isSheet('confirm')">
                    <div>
                        <p x-text="sheetMessage"></p>
                        <div class="site-screen__row"><button type="button" class="btn btn--ghost" x-on:click="answer(false)" x-text="t('cancel')"></button><button type="button" class="btn btn--primary" x-on:click="answer(true)" x-text="t('ok')"></button></div>
                    </div>
                </template>
                @include('audit.partials.report')
                @include('audit.partials.local-photos')
                <template x-if="isSheet('navigate')">
                    <div>
                        <h2 class="site-screen__label" x-text="navigateTitle"></h2>
                        <p class="site-screen__muted" x-text="navigateCoordinates"></p>
                        <template x-for="link in navigationLinks" x-bind:key="link.key">
                            <a class="site-screen__shot" x-bind:href="link.url" target="_blank" rel="noopener" x-on:click="rememberNavigation(link.key)" x-text="link.label"></a>
                        </template>
                        <button type="button" class="btn btn--ghost" x-on:click="closeSheet" x-text="t('close')"></button>
                    </div>
                </template>
                <template x-if="isSheet('refused')">
                    <div>
                        <h2 class="site-screen__label" x-text="t('refused_title')"></h2>
                        <p class="site-screen__muted" x-text="t('refused_hint')"></p>
                        <template x-for="item in refused" x-bind:key="item.id">
                            <div class="site-screen__refused">
                                <span x-text="refusedText(item)"></span>
                                <span class="site-screen__muted" x-show="isConflict(item)" x-text="conflictMine(item)"></span>
                                <a class="btn btn--ghost" x-show="item.blob" x-bind:href="refusedFileUrl(item)" download="photo.jpg" x-text="t('save_photo')"></a>
                                <button type="button" class="btn btn--primary" x-show="isConflict(item)" x-on:click="keepMine(item)" x-text="t('keep_mine')"></button>
                                <button type="button" class="btn btn--ghost" x-show="isConflict(item)" x-on:click="keepTheirs(item)" x-text="t('keep_theirs')"></button>
                                <button type="button" class="btn btn--ghost" x-show="!isConflict(item)" x-on:click="dismissRefused(item.id)" x-text="t('discard')"></button>
                            </div>
                        </template>
                        <button type="button" class="btn btn--ghost" x-on:click="closeSheet" x-text="t('close')"></button>
                    </div>
                </template>
            </div>
        </div>

        <div class="site-screen__overlay site-screen__overlay--zoom" x-show="zoom" x-on:click="closeZoom"><img class="site-screen__zoom" x-bind:src="zoomUrl" alt=""></div>
    </div>

    @include('audit.partials.render-failure')

    <div class="toast" data-toast role="status" aria-live="polite"></div>
    @livewireScripts
</body>
</html>
