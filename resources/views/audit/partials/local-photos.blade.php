<template x-if="isSheet('local')">
    <div class="site-screen__local">
        <h2 class="site-screen__label" x-text="t('local_photos')"></h2>
        <p class="site-screen__muted" x-text="t('local_hint')"></p>
        <p class="site-screen__muted" x-show="localUsageShown" x-text="localUsageText"></p>
        <p class="site-screen__muted" x-show="localEmpty" x-text="t('local_empty')"></p>
        <strong x-show="!localEmpty" x-text="localTotalText"></strong>
        <div class="site-screen__local-list">
            <template x-for="audit in local" x-bind:key="audit.cabinet">
                <section class="site-screen__local-audit">
                    <div class="site-screen__row">
                        <div class="site-screen__col">
                            <strong x-text="audit.cabinet"></strong>
                            <span class="site-screen__muted" x-text="localCountsText(audit)"></span>
                        </div>
                        <button type="button" class="btn btn--ghost btn--danger" x-show="audit.synced" x-on:click="deleteLocalAudit(audit)" x-text="localDeleteText(audit)"></button>
                    </div>
                    <template x-for="subject in audit.subjects" x-bind:key="subject.key">
                        <div class="site-screen__local-subject">
                            <div class="site-screen__row">
                                <div class="site-screen__col">
                                    <span x-text="subject.label"></span>
                                    <span class="site-screen__muted" x-text="localCountsText(subject)"></span>
                                </div>
                                <button type="button" class="btn btn--ghost" x-on:click="toggleLocal(subject)" x-text="localToggleText(subject)"></button>
                                <button type="button" class="btn btn--ghost btn--danger" x-show="subject.synced" x-on:click="deleteLocalSubject(subject)" x-text="localDeleteText(subject)"></button>
                            </div>
                            <template x-if="isLocalOpen(subject)">
                                <div class="site-screen__photos">
                                    <template x-for="photo in subject.photos" x-bind:key="photo.id">
                                        <div class="site-screen__photo" x-bind:class="photoClass(photo)">
                                            <img x-bind:src="photo.thumb_url" alt="" loading="lazy" x-on:click="openZoom(photo)">
                                            <span class="site-screen__photo-wait" x-show="photo.pending" aria-hidden="true">⏳</span>
                                            <button type="button" class="site-screen__photo-delete" x-show="photo.deletable" x-on:click.stop="deleteLocalPhoto(photo)" x-bind:aria-label="removeText">✕</button>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </template>
                </section>
            </template>
        </div>
        <button type="button" class="btn btn--ghost" x-on:click="closeSheet" x-text="t('close')"></button>
    </div>
</template>
