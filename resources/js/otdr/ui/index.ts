import type { AlpineMagics } from './alpine';
import './alpine';

/**
 * Registers the « Recette OTDR » page with Alpine. The screen and the measurement library are a separate chunk, fetched
 * only when the page is opened (and kept by the service worker for offline use).
 */
const loader = () => ({
    ready: false,
    failed: false,

    get loading(): boolean {
        return !this.ready && !this.failed;
    },

    init(this: { ready: boolean; failed: boolean } & AlpineMagics): void {
        // A page restored from the browsing history may still hold the screen drawn last time: it is drawn again.
        this.$root.querySelectorAll(':scope > .otdr__screen').forEach((element) => element.remove());

        import('./screen')
            .then((screen) => {
                screen.register(this.$root.dataset);
                this.ready = true;
            })
            .catch(() => {
                this.failed = true;
            });
    },
});

document.addEventListener('alpine:init', () => window.Alpine.data('otdrLoader', loader));
