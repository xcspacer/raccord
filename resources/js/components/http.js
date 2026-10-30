/**
 * Network requests with a time limit: on a slow or broken field connection a request must never leave the screen
 * waiting forever. Limits as in the legacy platform: data 12 s, files 30 s, sending (photos, forms) 2 min.
 */
export const TIMEOUTS = Object.freeze({
    data: 12000,
    file: 30000,
    upload: 120000,
});

/**
 * Time limit of a request: sending methods get the upload limit, reads the limit of their kind.
 *
 * @param {RequestInit|undefined} init
 * @param {'data'|'file'} kind
 */
export function timeoutFor(init, kind = 'data') {
    const method = String(init?.method ?? 'GET').toUpperCase();

    if (method !== 'GET' && method !== 'HEAD') {
        return TIMEOUTS.upload;
    }

    return TIMEOUTS[kind] ?? TIMEOUTS.data;
}

/**
 * fetch() aborted after the time limit (an AbortError, handled like being offline). A signal given by the caller is
 * kept and still aborts the request.
 *
 * @param {RequestInfo|URL} input
 * @param {RequestInit} [init]
 * @param {{kind?: 'data'|'file', timeout?: number}} [options]
 * @returns {Promise<Response>}
 */
export function fetchWithTimeout(input, init = {}, { kind = 'data', timeout } = {}) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(new DOMException('Request timed out', 'AbortError')), timeout ?? timeoutFor(init, kind));
    const forward = () => controller.abort(init.signal.reason);

    if (init.signal?.aborted) {
        forward();
    } else {
        init.signal?.addEventListener('abort', forward, { once: true });
    }

    return fetch(input, { ...init, signal: controller.signal }).finally(() => {
        clearTimeout(timer);
        init.signal?.removeEventListener('abort', forward);
    });
}
