import boxScreen from './box-screen';
import { pendingCount } from './db';
import locateList from './locate-list';
import planViewer from './plan-viewer';
import pointScreen from './point-screen';
import syncChip from './sync-chip';
import { wipeAndNotify } from './sync';

/**
 * Registers the audit screens with Alpine and the service worker that keeps them available without a network.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('auditBox', boxScreen);
    window.Alpine.data('auditPoint', pointScreen);
    window.Alpine.data('auditPlan', planViewer);
    window.Alpine.data('auditLocate', locateList);
    window.Alpine.data('auditSyncChip', syncChip);
});

if ('serviceWorker' in navigator && document.body?.dataset.userId) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/audit-service-worker.js').catch(() => {}));
}

/**
 * Signing out removes what was kept for the signed-in person from this device, except what they did not send yet
 * (sent when they sign in again). The person is warned first when something is still waiting.
 */
document.addEventListener('submit', async (event) => {
    const form = event.target;

    if (!(form instanceof HTMLFormElement) || new URL(form.action, window.location.href).pathname !== '/logout' || form.dataset.wiped) {
        return;
    }

    event.preventDefault();

    try {
        const pending = await pendingCount();

        if (pending > 0 && form.dataset.pendingConfirm && !window.confirm(form.dataset.pendingConfirm.replaceAll(':count', String(pending)))) {
            return;
        }

        await wipeAndNotify();
    } catch {
        // Signing out never waits on the phone's storage.
    }

    form.dataset.wiped = '1';
    form.submit();
});
