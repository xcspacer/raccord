/**
 * Layout values for elements whose position depends on data (planning timeline, progress bars…).
 * Markup carries `data-vars="start:3;span:5"`; each pair becomes a CSS custom property (--start, --span)
 * set through the CSSOM, so no inline style attribute is needed. Idempotent, and re-applied when
 * Livewire morphs the page.
 */
function apply(root) {
    root.querySelectorAll('[data-vars]').forEach((element) => {
        element.dataset.vars.split(';').forEach((pair) => {
            const [name, value] = pair.split(':');

            if (name && value !== undefined && element.style.getPropertyValue(`--${name}`) !== value) {
                element.style.setProperty(`--${name}`, value);
            }
        });
    });
}

/**
 * Scrolls a container once so that "today" sits at 30% of its width.
 */
function scrollToToday(root) {
    root.querySelectorAll('[data-scroll-today]').forEach((container) => {
        const index = Number(container.dataset.scrollToday);

        if (container.dataset.scrolled || Number.isNaN(index) || container.dataset.scrollToday === '') {
            return;
        }

        const dayWidth = parseFloat(getComputedStyle(container.firstElementChild).getPropertyValue('--day-w')) || 24;
        container.scrollLeft = Math.max(0, index * dayWidth - Math.round(container.clientWidth * 0.3));
        container.dataset.scrolled = '1';
    });
}

/**
 * Hides elements carrying `data-expire-at="<unix seconds>"` once that moment has passed (undo bars).
 */
function expire(root) {
    const now = Date.now() / 1000;

    root.querySelectorAll('[data-expire-at]').forEach((element) => {
        element.hidden = Number(element.dataset.expireAt) <= now;
    });
}

export function initDataVars() {
    setInterval(() => expire(document), 1000);

    let scheduled = false;

    const refresh = () => {
        scheduled = false;
        apply(document);
        scrollToToday(document);
        expire(document);
    };

    const schedule = () => {
        if (!scheduled) {
            scheduled = true;
            requestAnimationFrame(refresh);
        }
    };

    new MutationObserver(schedule).observe(document.documentElement, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['data-vars', 'style'],
    });

    document.addEventListener('DOMContentLoaded', schedule);
    document.addEventListener('livewire:navigated', schedule);
}
