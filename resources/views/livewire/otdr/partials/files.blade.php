<section class="otdr__card">
    <h2 class="otdr__heading">{{ __('otdr.files.heading') }}</h2>
    <div x-bind:class="dragOver ? 'otdr__drop is-over' : 'otdr__drop'" x-on:dragover="onDragOver($event)" x-on:dragleave="onDragLeave" x-on:drop="onDrop($event)">
        <div class="otdr__row otdr__row--center">
            <button type="button" class="otdr__btn otdr__btn--primary" x-on:click="chooseFiles">{{ __('otdr.files.choose_files') }}</button>
            <button type="button" class="otdr__btn" x-on:click="chooseFolder">{{ __('otdr.files.choose_folder') }}</button>
        </div>
        <p class="otdr__muted otdr__drop-help">{{ __('otdr.files.help') }}</p>
        <input type="file" multiple hidden x-ref="files" x-on:change="onFiles($event)">
        <input type="file" multiple hidden webkitdirectory x-ref="folder" x-on:change="onFiles($event)">
        <template x-if="reading">
            <div class="otdr__progress" role="progressbar" aria-label="{{ __('otdr.files.reading') }}"><i x-effect="paintProgress($el)"></i></div>
        </template>
    </div>
    <p class="otdr__muted otdr__note-last" x-show="hasDossier" x-text="views.filesSummary"></p>
</section>
