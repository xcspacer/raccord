<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <x-partials.head-meta :title="__('design_office_workflow.annotations.title', ['box' => $photo->box?->name ?? ''])" />
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="field-body">
    {{-- Alpine runs in its CSP-safe build: the logic lives in resources/js/design-office-workflow/photo-markup.js. --}}
    <div class="photo-markup" x-data="photoMarkup" data-config="{{ json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}" x-cloak>
        <header class="photo-markup__top">
            <button type="button" class="btn btn--ghost" x-on:click="back" x-text="backText"></button>
            <h1 class="photo-markup__title">{{ __('design_office_workflow.annotations.title', ['box' => $photo->box?->name ?? '']) }}</h1>
        </header>
        <p class="photo-markup__hint">{{ __('design_office_workflow.annotations.internal') }}</p>

        <div class="photo-markup__tools" x-show="canDraw">
            <template x-for="tool in tools" x-bind:key="tool">
                <button type="button" class="btn btn--ghost" x-bind:class="toolClass(tool)" x-on:click="pick(tool)" x-text="toolLabel(tool)"></button>
            </template>
            <template x-for="colour in colours" x-bind:key="colour">
                <button type="button" class="photo-markup__swatch" x-bind:class="swatchClass(colour)" x-on:click="paint(colour)" x-bind:aria-label="colourLabel(colour)"></button>
            </template>
            <input class="photo-markup__text" maxlength="120" x-show="textTool" x-model="text" x-bind:placeholder="textPlaceholder">
            <button type="button" class="btn btn--ghost" x-bind:disabled="nothingToUndo" x-on:click="undo" x-text="undoText"></button>
            <button type="button" class="btn btn--ghost" x-bind:disabled="nothingToUndo" x-on:click="clearMine" x-text="clearText"></button>
            <button type="button" class="btn btn--primary" x-bind:disabled="saving" x-on:click="save" x-text="saveText"></button>
        </div>

        <div class="photo-markup__stage">
            <img class="photo-markup__photo" x-ref="photo" x-bind:src="photoUrl" alt="" x-on:load="loaded">
            <svg class="photo-markup__layer" x-ref="layer" xmlns="http://www.w3.org/2000/svg" x-on:pointerdown="down($event)" x-on:pointermove="move($event)" x-on:pointerup="up($event)" x-on:pointerleave="up($event)"></svg>
        </div>

        <ul class="photo-markup__authors">
            <template x-for="annotation in annotations" x-bind:key="annotation.id">
                <li x-text="authorText(annotation)"></li>
            </template>
            <li x-show="noAnnotations" x-text="emptyText"></li>
        </ul>
    </div>

    <div class="toast" data-toast role="status" aria-live="polite"></div>
    @livewireScripts
</body>
</html>
