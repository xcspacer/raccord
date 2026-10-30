@inject('company', 'App\Settings\CompanySettings')

{{-- Markup of the file viewer, the full-screen photo viewer and the open/save sheet; resources/js/components/file-viewer.js clones it when needed. --}}
<template data-file-viewer-template data-failed="{{ __('file_viewer.failed') }}">
    <div class="overlay file-viewer" role="dialog" aria-modal="true">
        <div class="file-viewer__modal">
            <div class="file-viewer__head">
                <button type="button" class="btn btn--ghost" data-overlay-close>{{ __('file_viewer.back') }}</button>
                <span class="file-viewer__title" data-file-viewer-title></span>
                <span class="file-viewer__pages" data-file-viewer-pages hidden></span>
                <button type="button" class="btn btn--ghost" data-file-viewer-zoom="-1" aria-label="{{ __('file_viewer.zoom_out') }}" hidden>−</button>
                <button type="button" class="btn btn--ghost" data-file-viewer-zoom="1" aria-label="{{ __('file_viewer.zoom_in') }}" hidden>+</button>
                <button type="button" class="btn btn--ghost" data-file-viewer-share hidden>{{ __('file_viewer.share') }}</button>
                <button type="button" class="btn btn--ghost" data-file-viewer-close>{{ __('file_viewer.close') }}</button>
            </div>
            <div class="file-viewer__loading" data-file-viewer-loading>…</div>
            <iframe class="file-viewer__frame" data-file-viewer-frame title="" hidden></iframe>
            <img class="file-viewer__image" data-file-viewer-image alt="" hidden>
            <div class="file-viewer__pdf" data-file-viewer-pdf hidden></div>
        </div>
    </div>
</template>

<template data-photo-viewer-template>
    <div class="overlay photo-viewer" role="dialog" aria-modal="true">
        <div class="photo-viewer__head">
            <button type="button" class="btn btn--ghost" data-overlay-close>{{ __('file_viewer.back') }}</button>
            @if ($logo = $company->logoUrl())
                <img class="photo-viewer__logo" src="{{ $logo }}" alt="{{ $company->displayName() }}">
            @endif
            <span class="photo-viewer__count" data-photo-viewer-count></span>
            <button type="button" class="btn btn--ghost" data-photo-viewer-close>{{ __('file_viewer.close') }}</button>
        </div>
        <div class="photo-viewer__stage" data-photo-viewer-stage>
            <button type="button" class="photo-viewer__nav photo-viewer__nav--previous" data-photo-viewer-move="-1" aria-label="{{ __('file_viewer.previous') }}">‹</button>
            <img class="photo-viewer__image" data-photo-viewer-image alt="">
            <button type="button" class="photo-viewer__nav photo-viewer__nav--next" data-photo-viewer-move="1" aria-label="{{ __('file_viewer.next') }}">›</button>
        </div>
        <div class="photo-viewer__foot">
            <span data-photo-viewer-name></span>
            <span class="photo-viewer__hint">{{ __('file_viewer.photo_hint') }}</span>
        </div>
    </div>
</template>

<template data-file-sheet-template data-size="{{ __('file_viewer.size_kb') }}">
    <div class="overlay file-sheet" role="dialog" aria-modal="true">
        <div class="file-sheet__box">
            <h3 class="file-sheet__name" data-file-sheet-name></h3>
            <p class="file-sheet__meta" data-file-sheet-meta></p>
            <button type="button" class="btn btn--primary file-sheet__action" data-file-sheet-share hidden>📤 {{ __('file_viewer.sheet_share') }}</button>
            <button type="button" class="btn btn--ghost file-sheet__action" data-file-sheet-save>💾 {{ __('file_viewer.sheet_save') }}</button>
            <button type="button" class="btn btn--ghost file-sheet__action" data-overlay-close>{{ __('file_viewer.close') }}</button>
        </div>
    </div>
</template>
