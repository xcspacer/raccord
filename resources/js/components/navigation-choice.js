/**
 * Waze / Google Maps choice of a place to drive to: the app chosen last time comes first.
 * The choice is shared with the audit box screen.
 */
const STORAGE_KEY = 'audit_navigation';

const lastChoice = () => {
    try {
        return window.localStorage?.getItem(STORAGE_KEY) ?? '';
    } catch {
        return '';
    }
};

export default () => ({
    preferred: '',

    init() {
        this.preferred = lastChoice() || 'waze';
    },

    linkClass(key) {
        return key === this.preferred ? 'btn--primary task-nav-sheet__link--first' : 'btn--ghost';
    },

    remember(key) {
        try {
            window.localStorage?.setItem(STORAGE_KEY, key);
        } catch {
            // Remembering the choice is a convenience only.
        }

        this.preferred = key;
    },
});
