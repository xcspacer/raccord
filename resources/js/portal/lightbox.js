/**
 * Client portal photo viewer: every photo of the open drawer, with previous / next, Esc and the arrow keys.
 */
export default () => ({
    photos: [],
    index: -1,

    init() {
        this.photos = JSON.parse(this.$el.dataset.photos ?? '[]');
        this.onKey = (event) => this.key(event);
        window.addEventListener('keydown', this.onKey);
    },

    destroy() {
        window.removeEventListener('keydown', this.onKey);
    },

    get isOpen() {
        return this.index >= 0;
    },

    get current() {
        return this.photos[this.index] ?? { full: '', download: '', caption: '' };
    },

    get src() {
        return this.current.full;
    },

    get download() {
        return this.current.download;
    },

    get caption() {
        return `${this.current.caption} · ${this.index + 1}/${this.photos.length}`;
    },

    show(index) {
        this.index = index;
    },

    close() {
        this.index = -1;
    },

    previous() {
        this.index = (this.index - 1 + this.photos.length) % this.photos.length;
    },

    next() {
        this.index = (this.index + 1) % this.photos.length;
    },

    key(event) {
        if (!this.isOpen) {
            return;
        }

        if (event.key === 'Escape') {
            this.close();
        } else if (event.key === 'ArrowLeft') {
            this.previous();
        } else if (event.key === 'ArrowRight') {
            this.next();
        }
    },
});
