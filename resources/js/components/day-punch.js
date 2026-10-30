import { fetchWithTimeout } from './http';

/**
 * Day punch (clock-in / clock-out) with GPS. Requests that cannot reach the server wait in a queue on the
 * device and are sent, oldest first, as soon as the connection is back; the server keeps its own clock
 * and only trusts the phone's time for requests that really waited.
 */
const QUEUE_KEY = 'raccord_punch_queue_v1';
const GEO_OPTIONS = { enableHighAccuracy: true, timeout: 12000, maximumAge: 30000 };
const CLOCK_MS = 15000;

const readQueue = () => {
    try {
        return JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]') || [];
    } catch {
        return [];
    }
};

const writeQueue = (queue) => {
    try {
        localStorage.setItem(QUEUE_KEY, JSON.stringify(queue));
    } catch {
        // storage unavailable: the request is simply not kept
    }
};

const fill = (template, values) => Object.entries(values).reduce((text, [key, value]) => text.replaceAll(`:${key}`, String(value)), template ?? '');

const xsrf = () => decodeURIComponent((document.cookie.split('; ').find((row) => row.startsWith('XSRF-TOKEN=')) ?? '').split('=')[1] ?? '');

const toast = (message) => window.dispatchEvent(new CustomEvent('toast', { detail: { message } }));

const position = () => new Promise((resolve) => {
    if (!navigator.geolocation) {
        resolve({ reason: 'unavailable' });

        return;
    }

    navigator.geolocation.getCurrentPosition(
        ({ coords }) => resolve({ lat: coords.latitude, lng: coords.longitude, accuracy: coords.accuracy }),
        (error) => resolve({ reason: error.code === 1 ? 'denied' : error.code === 3 ? 'timeout' : 'unavailable' }),
        GEO_OPTIONS,
    );
});

/**
 * Sends one request. Resolves with the server's answer, or { network: true } when it could not be reached.
 */
const post = async (endpoint, operation, payload) => {
    try {
        const response = await fetchWithTimeout(`${endpoint}/${operation}`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
            body: JSON.stringify(payload),
        });

        return { ...(await response.json().catch(() => ({ ok: false }))), status: response.status };
    } catch {
        return { network: true };
    }
};

const drain = async () => {
    const root = document.querySelector('[data-day-punch]');
    let sent = 0;

    for (const item of readQueue()) {
        const answer = await post(item.endpoint, item.operation, item.payload);

        if (answer.network) {
            break;
        }

        writeQueue(readQueue().filter((queued) => queued.id !== item.id));
        sent++;
    }

    if (sent > 0) {
        window.Livewire?.dispatch('day-punch-changed');
    }

    return root;
};

const run = async (operation, container, button) => {
    const labels = JSON.parse(container.dataset.labels || '{}');
    const endpoint = container.dataset.endpoint;
    const message = container.querySelector('[data-punch-message]');

    button.disabled = true;

    if (message) {
        message.textContent = labels.locating;
    }

    const place = await position();
    const payload = { ...place, client_at: new Date().toISOString(), queued: false };
    const answer = await post(endpoint, operation, payload);

    if (answer.network) {
        writeQueue([...readQueue(), { id: crypto.randomUUID?.() ?? String(Date.now()), endpoint, operation, payload: { ...payload, queued: true } }]);
        toast(labels.queued);
        window.Livewire?.dispatch('day-punch-changed');
        hidePendingGate();
    } else if (answer.ok) {
        toast(fill(operation === 'start' ? labels.started : labels.ended, { time: operation === 'start' ? answer.start : answer.end }));
        window.Livewire?.dispatch('day-punch-changed');
    } else {
        if (message) {
            message.textContent = answer.message || labels.network_error;
        } else {
            toast(answer.message || labels.network_error);
        }

        button.disabled = false;

        return;
    }

    if (message) {
        message.textContent = place.reason === 'denied' ? labels.denied_hint : '';
    }

    button.disabled = false;
};

/**
 * A start waiting in the queue counts as done on this device: the blocking window stays out of the way.
 */
const hidePendingGate = () => {
    const waiting = readQueue().some((item) => item.operation === 'start');

    document.querySelectorAll('.overlay--gate').forEach((overlay) => {
        overlay.hidden = waiting;
    });
};

const updateClocks = () => {
    document.querySelectorAll('[data-punch-clock]').forEach((clock) => {
        const root = clock.closest('[data-day-punch]');
        const timeZone = root?.dataset.timezone;
        const lang = document.documentElement.lang || undefined;
        const day = new Intl.DateTimeFormat(lang, { weekday: 'long', day: '2-digit', month: 'long', timeZone }).format(new Date());
        const time = new Intl.DateTimeFormat(lang, { hour: '2-digit', minute: '2-digit', hourCycle: 'h23', timeZone }).format(new Date());
        clock.textContent = `${day} — ${time}`;
    });
};

export function initDayPunch() {
    document.addEventListener('click', (event) => {
        const start = event.target.closest('[data-punch-start]');
        const end = event.target.closest('[data-punch-end]');
        const target = start ?? end;

        if (!target) {
            return;
        }

        const container = target.closest('[data-day-punch]');
        const labels = JSON.parse(container?.dataset.labels || '{}');

        if (end && !window.confirm(fill(labels.end_confirm, { time: end.dataset.started }))) {
            return;
        }

        run(start ? 'start' : 'end', container, target);
    });

    window.addEventListener('online', drain);
    document.addEventListener('DOMContentLoaded', () => {
        drain();
        updateClocks();
        hidePendingGate();
    });
    document.addEventListener('livewire:navigated', () => {
        drain();
        hidePendingGate();
    });
    new MutationObserver(hidePendingGate).observe(document.documentElement, { childList: true, subtree: true });
    setInterval(updateClocks, CLOCK_MS);
}
