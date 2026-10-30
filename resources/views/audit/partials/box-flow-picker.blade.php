{{-- Photos of the box to choose in a design office workflow sheet (sent or still waiting on the phone). --}}
<p class="site-screen__muted" x-text="ft('photos_hint')"></p>
<p class="site-screen__muted" x-show="pickNone" x-text="ft('no_photos')"></p>
<div class="site-screen__photos">
    <template x-for="photo in pickable" x-bind:key="photo.id">
        <div class="site-screen__photo" x-bind:class="pickClass(photo)">
            <img x-bind:src="photo.thumb_url" alt="" loading="lazy" x-on:click="togglePicked(photo)">
            <span class="site-screen__photo-check" x-text="pickMark(photo)" aria-hidden="true"></span>
        </div>
    </template>
</div>
