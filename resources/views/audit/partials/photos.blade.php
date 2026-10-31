<div class="site-screen__photos">
    <template x-for="photo in {{ $photos }}" x-bind:key="photo.id">
        <div class="site-screen__photo" x-bind:class="photoClass(photo)" x-bind:draggable="canDrag(photo)" x-on:dragstart="dragPhoto(photo, $event)" x-on:dragend="dragEnd">
            <img x-bind:src="photo.thumb_url" alt="" loading="lazy" x-on:click="photoClick(photo)">
            <span class="site-screen__photo-check" x-show="selecting && !photo.pending" x-text="selectMark(photo)" aria-hidden="true"></span>
            <span class="site-screen__photo-wait" x-show="photo.pending" aria-hidden="true">⏳</span>
            <span class="site-screen__photo-nogps" x-show="photo.no_gps && manager && !photo.pending" x-text="t('no_gps_mark')"></span>
            <button type="button" class="site-screen__photo-delete" x-show="canDelete(photo) && !selecting" x-on:click.stop="deletePhoto(photo)" x-bind:aria-label="removeText">✕</button>
        </div>
    </template>
</div>
