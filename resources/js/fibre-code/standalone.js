import './index';

/**
 * Standalone « Code Fibre » app: the service worker keeps it usable without a network once opened. It is told which
 * files the page has loaded (styles, scripts, fonts) so they are all kept for the next offline visit.
 */
const { serviceWorker: workerUrl, scope } = document.body.dataset;

if ('serviceWorker' in navigator && workerUrl && scope) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register(workerUrl, { scope })
            .then(() => navigator.serviceWorker.ready)
            .then((registration) => registration.active?.postMessage({
                type: 'keep',
                urls: performance.getEntriesByType('resource').map((entry) => entry.name),
            }))
            .catch(() => {});
    });
}
