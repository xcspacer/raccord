/**
 * Media library folders dragged onto another of the same level: after a confirmation, their contents are merged.
 */
export default () => ({
    dragged: null,
    over: null,

    start(event) {
        this.dragged = Number(event.currentTarget.dataset.folder);
        event.dataTransfer.setData('text/plain', 'folder');
        event.dataTransfer.effectAllowed = 'move';
    },

    enter(event) {
        const id = Number(event.currentTarget.dataset.folder);

        if (this.dragged !== null && this.dragged !== id) {
            event.preventDefault();
            this.over = id;
        }
    },

    leave() {
        this.over = null;
    },

    end() {
        this.dragged = null;
        this.over = null;
    },

    overClass(id) {
        return this.over === id ? 'is-drop-target' : '';
    },

    drop(event) {
        event.preventDefault();

        const target = event.currentTarget;
        const source = this.$root.querySelector(`[data-folder="${this.dragged}"]`);
        const sourceId = this.dragged;

        this.end();

        if (sourceId === null || source === null || Number(target.dataset.folder) === sourceId) {
            return;
        }

        const question = this.$root.dataset.confirm.replace(':source', source.dataset.label).replace(':target', target.dataset.label);

        if (window.confirm(question)) {
            this.$wire.move(sourceId, Number(target.dataset.folder));
        }
    },
});
