import { showToast } from './toast';

const AUDIT_ENTRIES = ['audits_optical', 'audits_full'];

/**
 * Menu badges for the client portal notes: the audit entries show the notes waiting to be taken into account
 * (and pulse), the « Contrôle client » entry the untreated ones; new notes show a toast and vibrate the phone.
 */
export function initClientNotes() {
    window.addEventListener('client-notes', ({ detail }) => {
        document.querySelectorAll('[data-menu-badge]').forEach((badge) => {
            const key = badge.dataset.menuBadge;
            const count = AUDIT_ENTRIES.includes(key) ? detail.pending : (key === 'client_control' ? detail.control : 0);

            badge.textContent = count > 0 ? String(count) : '';
            badge.hidden = count === 0;
            badge.closest('.fn-item')?.classList.toggle('has-alert', AUDIT_ENTRIES.includes(key) && count > 0);
        });

        if (detail.fresh) {
            showToast(detail.fresh);

            try {
                navigator.vibrate?.([180, 80, 180]);
            } catch {
                // Vibration is optional.
            }
        }
    });
}
