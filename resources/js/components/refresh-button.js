/**
 * Header "⟳" button: reloads the page and, if the reload happens within two minutes for the same person,
 * restores the scroll position (page and horizontal scroll of the timeline).
 */
const KEY = 'raccord_ui_state';
const MAX_AGE_MS = 120000;

const save = () => {
    try {
        sessionStorage.setItem(KEY, JSON.stringify({
            user: document.body.dataset.userId,
            at: Date.now(),
            path: location.pathname + location.search,
            y: window.scrollY,
            timeline: document.querySelector('.timeline-scroll')?.scrollLeft ?? null,
        }));
    } catch {
        // storage unavailable: the page simply reloads at the top
    }
};

const restore = () => {
    try {
        const state = JSON.parse(sessionStorage.getItem(KEY) || 'null');
        sessionStorage.removeItem(KEY);

        if (!state || state.user !== document.body.dataset.userId || Date.now() - state.at > MAX_AGE_MS || state.path !== location.pathname + location.search) {
            return;
        }

        window.scrollTo(0, state.y);

        const timeline = document.querySelector('.timeline-scroll');

        if (timeline && state.timeline !== null) {
            timeline.dataset.scrolled = '1';
            timeline.scrollLeft = state.timeline;
        }
    } catch {
        // unreadable state: ignore
    }
};

export function initRefreshButtons(root = document) {
    root.querySelectorAll('[data-action="refresh"]').forEach((button) => {
        if (button.dataset.bound) {
            return;
        }

        button.dataset.bound = '1';
        button.addEventListener('click', () => {
            button.classList.add('is-spinning');
            save();
            window.location.reload();
        });
    });

    if (root === document) {
        setTimeout(restore, 400);
    }
}
