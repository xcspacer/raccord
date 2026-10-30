/**
 * Android "Back" (and the browser's back button) closes the window on top instead of leaving the page.
 * Each open window (.overlay) adds a history entry; going back clicks its close button (wire:click="close", or
 * data-overlay-close for windows drawn by JavaScript such as the file viewers).
 */
export function initBackStack() {
    let depth = 0;
    let ignored = 0;

    const sync = () => {
        const open = document.querySelectorAll('.overlay').length;

        while (depth < open) {
            history.pushState({ overlay: true }, '');
            depth++;
        }

        while (depth > open) {
            depth--;
            ignored++;
            history.back();
        }
    };

    new MutationObserver(sync).observe(document.documentElement, { childList: true, subtree: true });

    window.addEventListener('popstate', () => {
        if (ignored > 0) {
            ignored--;

            return;
        }

        const overlays = document.querySelectorAll('.overlay');

        if (depth > 0 && overlays.length > 0) {
            depth--;
            overlays[overlays.length - 1].querySelector('[wire\\:click="close"], [data-overlay-close]')?.click();
        }
    });
}
