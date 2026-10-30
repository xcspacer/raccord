<template x-if="isSheet('report')">
    <div class="site-screen__report">
        <h2 class="site-screen__label" x-text="reportTitle"></h2>
        <input class="site-screen__input" type="search" x-model="reportSearch" x-bind:placeholder="reportSearchPlaceholder" x-bind:aria-label="reportSearchPlaceholder">
        <p class="site-screen__muted" x-show="reportEmpty" x-text="t('report_empty')"></p>
        <div class="site-screen__report-list">
            <template x-for="row in reportRows" x-bind:key="row.id">
                <button type="button" class="site-screen__pick" x-bind:class="pickClass(row)" x-on:click="togglePick(row)">
                    <span class="site-screen__pick-mark" x-text="pickMark(row)"></span>
                    <span x-text="rowLabel(row)"></span>
                </button>
            </template>
        </div>
        <div class="site-screen__row">
            <strong x-text="reportCountText"></strong>
            <button type="button" class="btn btn--ghost" x-on:click="closeSheet" x-text="t('cancel')"></button>
            <button type="button" class="btn btn--primary" x-on:click="saveReport" x-text="t('report_validate')"></button>
        </div>
    </div>
</template>
