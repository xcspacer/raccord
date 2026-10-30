/**
 * With the screen off or the app in the background nothing is refreshed: the periodic Livewire refreshes are
 * skipped while the page is hidden (unless marked keep-alive). Back in front, when the last refresh is older than
 * 20 s, every periodic refresh of the page runs at once.
 */
export const CATCH_UP_AFTER = 20000;

export const needsCatchUp = (lastRefreshAt, now) => now - lastRefreshAt > CATCH_UP_AFTER;

let lastRefreshAt = Date.now();

const pollAttribute = (element) => [...element.attributes].find((attribute) => attribute.name === 'wire:poll' || attribute.name.startsWith('wire:poll.'));

const catchUp = () => {
    for (const element of document.querySelectorAll('[wire\\:id], [wire\\:id] *')) {
        const attribute = pollAttribute(element);
        const component = attribute ? window.Livewire?.find(element.closest('[wire\\:id]')?.getAttribute('wire:id')) : null;

        if (component) {
            const method = attribute.value.trim();

            (method === '' ? component.$refresh() : component.$call(method))?.catch?.(() => {});
        }
    }
};

export function initBackgroundPause() {
    document.addEventListener('livewire:init', () => {
        window.Livewire.interceptAction(({ action }) => {
            if (action.metadata?.type !== 'poll' || action.origin?.directive?.modifiers?.includes('keep-alive')) {
                return;
            }

            if (document.hidden) {
                action.cancel();

                return;
            }

            lastRefreshAt = Date.now();
        });
    });

    document.addEventListener('visibilitychange', () => {
        if (document.hidden || !needsCatchUp(lastRefreshAt, Date.now())) {
            return;
        }

        lastRefreshAt = Date.now();
        catchUp();
    });
}
