/**
 * Unexpected errors are kept on the device (the last 40) to help a diagnosis; nothing is sent anywhere and keeping
 * them never gets in the way of the person.
 */
const KEY = 'raccord_error_log';
export const KEEP = 40;
const MESSAGE_LENGTH = 300;

/**
 * The log with one more entry, keeping only the most recent ones.
 *
 * @param {Array<{at: string, kind: string, message: string}>} entries
 */
export function appendEntry(entries, entry, keep = KEEP) {
    return [...(Array.isArray(entries) ? entries : []), entry].slice(-keep);
}

const describe = (value) => {
    if (value instanceof Error) {
        return value.stack || value.message;
    }

    return String(value ?? '');
};

export function logError(kind, value) {
    try {
        const entries = JSON.parse(window.localStorage.getItem(KEY) ?? '[]');
        const entry = { at: new Date().toISOString(), kind, message: describe(value).slice(0, MESSAGE_LENGTH) };

        window.localStorage.setItem(KEY, JSON.stringify(appendEntry(entries, entry)));
    } catch {
        // Without storage the error is simply not kept.
    }
}

export function readErrorLog() {
    try {
        return JSON.parse(window.localStorage.getItem(KEY) ?? '[]');
    } catch {
        return [];
    }
}

export function initErrorLog() {
    window.addEventListener('error', (event) => {
        logError('error', `${event.message ?? ''} @${String(event.filename ?? '').split('/').pop()}:${event.lineno ?? ''}`);
    });
    window.addEventListener('unhandledrejection', (event) => logError('rejection', event.reason));
}
