import { pendingCount } from './db';

const REFRESH_EVERY = 5000;

/**
 * « ✓ Synchronisé » chip of the audit list and of a cabinet page: what this phone still has to send (photos and
 * changes made on the field screens) and whether it is online. The texts come from the element (data-labels).
 */
export default () => ({
    tone: 'ok',
    text: '',
    labels: {},
    timer: null,
    onNetwork: null,

    init() {
        try {
            this.labels = JSON.parse(this.$el.dataset.labels ?? '{}');
        } catch {
            this.labels = {};
        }

        this.text = this.$el.textContent.trim();
        this.onNetwork = () => this.update();
        window.addEventListener('online', this.onNetwork);
        window.addEventListener('offline', this.onNetwork);
        this.timer = setInterval(() => this.update(), REFRESH_EVERY);
        this.update();
    },

    destroy() {
        clearInterval(this.timer);
        window.removeEventListener('online', this.onNetwork);
        window.removeEventListener('offline', this.onNetwork);
    },

    async update() {
        let pending = 0;

        try {
            pending = await pendingCount();
        } catch {
            pending = 0;
        }

        const state = chipState(navigator.onLine, pending);
        this.tone = state.tone;
        this.text = (this.labels[state.label] ?? this.text).replaceAll(':count', String(pending));
    },

    get chipClass() {
        return `audit__sync audit__sync--${this.tone}`;
    },
});

/**
 * Tone and text of the chip for a network state and a number of items waiting to be sent.
 *
 * @returns {{tone: 'ok'|'wait'|'off', label: 'synced'|'pending'|'offline'|'offline_pending'}}
 */
export function chipState(online, pending) {
    if (!online) {
        return { tone: 'off', label: pending > 0 ? 'offline_pending' : 'offline' };
    }

    return pending > 0 ? { tone: 'wait', label: 'pending' } : { tone: 'ok', label: 'synced' };
}
