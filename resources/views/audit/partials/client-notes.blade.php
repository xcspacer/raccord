<div class="audit-client-notes" role="status" x-show="clientNotes.length > 0">
    <b x-text="clientNotesTitle"></b>
    <template x-for="note in clientNotes" x-bind:key="note.id">
        <div class="audit-client-notes__item">
            <div class="audit-client-notes__head" x-text="clientNoteHead(note)"></div>
            <div class="audit-client-notes__text" x-text="note.text"></div>
            <button type="button" class="btn btn--primary audit-client-notes__ack" x-on:click="acknowledgeClientNote(note)" x-text="clientNoteAckText"></button>
        </div>
    </template>
</div>
