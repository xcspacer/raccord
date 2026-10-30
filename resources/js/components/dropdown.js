const SELECTOR = 'details.dropdown, details.lang-dropdown, details.portal-bell';

/**
 * Header menus built on <details>: opening one closes the others, a click outside or Esc closes them.
 */
export function initDropdowns() {
    document.addEventListener('toggle', (event) => {
        const opened = event.target;

        if (!(opened instanceof HTMLDetailsElement) || !opened.matches(SELECTOR) || !opened.open) {
            return;
        }

        document.querySelectorAll(SELECTOR).forEach((other) => {
            if (other !== opened) {
                other.open = false;
            }
        });
    }, true);

    document.addEventListener('click', (event) => {
        document.querySelectorAll(`${SELECTOR}[open]`).forEach((details) => {
            if (!details.contains(event.target)) {
                details.open = false;
            }
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            document.querySelectorAll(`${SELECTOR}[open]`).forEach((details) => {
                details.open = false;
            });
        }
    });
}
