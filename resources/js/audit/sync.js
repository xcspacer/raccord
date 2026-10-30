import { dbAll, dbClear, dbDelete, dbGet, dbPut, dropExpired, storage, wipeLocalData } from './db';
import { keepLocalCopy } from './local-photos';
import { forgetBlob } from './replay';

/**
 * Version of what this code sends to the sync endpoints (EnsureCurrentSyncProtocol::VERSION on the server).
 */
export const PROTOCOL_VERSION = 1;
export const REVOKED_REASONS = ['session_revoked', 'user_disabled'];
export const WIPE_CHANNEL = 'audit_field';

const RETRY_AFTER = 20000;
const FLUSH_EVERY = 30000;
const POLL_EVERY = 15000;
export const CATCH_UP_AFTER = 20000;
export const STALE_AFTER = 24 * 3600 * 1000;

let counter = 0;

const newId = () => `o_${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`;
const nextSeq = () => Date.now() * 1000 + (counter++ % 1000);

/**
 * Back in front, the audit is fetched again at once when the last answer of the server is older than 20 s.
 */
export const needsCatchUp = (lastRefreshAt, now) => now - lastRefreshAt > CATCH_UP_AFTER;

/**
 * Photos of any audit still waiting on this phone for more than 24 h.
 */
export const stalePhotoCount = (queue, now) => queue.filter((item) => item.type === 'photo' && now - (item.created_at ?? now) > STALE_AFTER).length;

/**
 * Asks the browser not to clear what is kept on the phone when it runs short of space (once per page).
 */
export async function requestPersistentStorage() {
    try {
        if (navigator.storage?.persist && !(await navigator.storage.persisted?.())) {
            await navigator.storage.persist();
        }
    } catch {
        // The browser decides; without it the local data simply stays « best effort ».
    }
}

/**
 * Wipes what the phone keeps for the audit screens (pending items stay, see db.js) and the pages and photos kept by
 * the service worker, and tells the other open tabs.
 */
export async function wipeAndNotify() {
    await wipeLocalData();
    globalThis.navigator?.serviceWorker?.controller?.postMessage('clear');

    try {
        const channel = new BroadcastChannel(WIPE_CHANNEL);
        channel.postMessage('wiped');
        channel.close();
    } catch {
        // Without BroadcastChannel the other tabs find out at their next request.
    }
}

/**
 * Whether the server refused a request because the person lost their access (not because the session simply ended).
 */
export const isRevoked = (status, answer) => status === 401 && REVOKED_REASONS.includes(answer?.reason ?? answer?.error);

/**
 * What to do with the answer of the server to a queued item: « done » (it leaves the queue), « auth » (stop until the
 * person signs in again), « retry » (stop and try again later), « conflict » (kept apart until the person chooses) or
 * « reject » (kept apart). An edit refused because it is already so (409) is done; a design office action (« flow »)
 * refused for any reason is kept apart with the server's explanation, since the person has to know it did not happen.
 */
export function outcomeOf(type, status, reason) {
    if (status >= 200 && status < 300) {
        return 'done';
    }

    if (status === 401 || status === 419) {
        return 'auth';
    }

    if (status === 408 || status === 429 || status >= 500) {
        return 'retry';
    }

    if (type === 'flow' || status !== 409) {
        return 'reject';
    }

    if (reason === 'conflict') {
        return 'conflict';
    }

    return reason === 'validated' ? 'reject' : 'done';
}

/** Fields of a design office action that name photos of the audit (several ids, or one). */
const PHOTO_LISTS = ['before_photo_ids', 'after_photo_ids'];
const PHOTO_FIELDS = ['photo_id', 'photo'];
const PENDING = 'pending:';

const swap = (value, from, to) => (value === from ? to : value);

/**
 * The body of a queued design office action once a photo it names, still waiting on this phone when the action was
 * made (`pending:<queue id>`), has reached the server with the given id. The same body when it names no such photo.
 */
export function withPhotoId(body, queueId, photoId) {
    const from = `${PENDING}${queueId}`;
    let changed = false;
    const next = { ...body };

    for (const key of PHOTO_LISTS.filter((name) => Array.isArray(body?.[name]))) {
        next[key] = body[key].map((value) => swap(value, from, photoId));
        changed ||= body[key].includes(from);
    }

    for (const key of PHOTO_FIELDS.filter((name) => body?.[name] === from)) {
        next[key] = photoId;
        changed = true;
    }

    return changed ? next : body;
}

/**
 * What is sent for a design office action: photos the server never received (refused, or sent before this phone
 * learned their id) are left out — the server then says when a required one is missing.
 */
export function resolvedBody(body) {
    const known = (value) => !(typeof value === 'string' && value.startsWith(PENDING));
    const next = { ...body };

    for (const key of PHOTO_LISTS.filter((name) => Array.isArray(body?.[name]))) {
        next[key] = body[key].filter(known);
    }

    for (const key of PHOTO_FIELDS.filter((name) => name in (body ?? {}) && !known(body[name]))) {
        next[key] = null;
    }

    return next;
}

export const xsrf = () => {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
};

/**
 * Keeps one audit usable without a network. Everything a person does becomes a queue item stored on the phone and
 * sent one at a time, in order, as soon as the network allows; the audit itself is reloaded from the server when its
 * revision changes. Items the server refuses are kept apart, never silently lost.
 *
 * Outcome of an item: 2xx done; 409 already so (kept apart when the audit was validated meanwhile); 5xx, 408, 429 or
 * no network: stop and try again later; 401/419: stop until the person signs in again — or, when the server says the
 * access was revoked, wipe the phone (pending items stay) and go to the sign-in page; 426: this code is out of date,
 * the page reloads; any other 4xx: refused.
 *
 * Only the items of the signed-in person are loaded and sent: the pending items of someone else who used this phone
 * stay until they sign in again. Without storage on the phone (memory only), nothing is accepted while offline.
 * Each item says when it was made and whether it waited in the queue, so the server can keep the time of the phone
 * within limits.
 *
 * Each edit carries the revision the phone was showing and the identifier of this device: when someone else (or
 * another device) changed the same field since, the server answers « conflict » and the edit is kept apart until the
 * person chooses to keep theirs (sent again with `keep_mine`) or the saved one.
 *
 * Actions of the design office workflow are queued the same way (type « flow »), each to its own address; whatever
 * the server refuses is kept apart with its explanation (see outcomeOf).
 */
export class SyncEngine extends EventTarget {
    constructor({ cabinet, urls, userId }) {
        super();
        this.cabinet = cabinet;
        this.urls = urls;
        this.userId = userId;
        this.queue = [];
        this.rejected = [];
        this.record = null;
        this.online = navigator.onLine;
        this.flushing = false;
        this.problem = null;
        this.retryTimer = null;
        this.lastRefreshAt = 0;
        this.otherPhotos = [];
        this.deviceId = null;
        this.stopped = false;
    }

    async init() {
        const meta = await dbGet('meta', 'user');

        if (meta && meta.userId !== this.userId) {
            for (const store of ['records', 'photos', 'workflow']) {
                await dbClear(store);
            }
        }

        await dbPut('meta', { key: 'user', userId: this.userId });
        await dropExpired();
        this.deviceId = (await dbGet('meta', 'device'))?.id ?? null;

        if (this.deviceId === null) {
            this.deviceId = `d_${newId().slice(2)}`;
            await dbPut('meta', { key: 'device', id: this.deviceId });
        }

        this.record = (await dbGet('records', this.cabinet)) ?? null;

        if (this.record) {
            this.record.opened_at = Date.now();
            await dbPut('records', this.record);
        }

        await this.reload();
        requestPersistentStorage();
    }

    /**
     * Items still in the queue when the page opens have waited, whatever happened before.
     */
    async reload() {
        const queue = (await dbAll('queue')).filter((item) => item.userId === this.userId);

        this.otherPhotos = queue.filter((item) => item.cabinet !== this.cabinet && item.type === 'photo').map(({ type, created_at }) => ({ type, created_at }));
        this.queue = queue.filter((item) => item.cabinet === this.cabinet).sort((a, b) => a.seq - b.seq).map((item) => ({ ...item, waited: true }));
        this.rejected = (await dbAll('rejected')).filter((item) => item.cabinet === this.cabinet && item.userId === this.userId).sort((a, b) => a.seq - b.seq);
        this.changed();
    }

    start() {
        try {
            new BroadcastChannel(WIPE_CHANNEL).addEventListener('message', (event) => event.data === 'wiped' && this.stop('revoked'));
        } catch {
            // Other tabs cannot tell this one; the server will, at the next request.
        }

        window.addEventListener('online', () => {
            this.online = true;
            this.changed();
            this.flush();
        });
        window.addEventListener('offline', () => {
            this.online = false;
            this.changed();
        });
        // Nothing is fetched while the page is hidden; back in front, what was missed is fetched at once.
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState !== 'visible') {
                return;
            }

            this.flush();

            if (this.queue.length === 0 && needsCatchUp(this.lastRefreshAt, Date.now())) {
                this.refresh(true);
            }
        });
        setInterval(() => this.queue.length > 0 && this.flush(), FLUSH_EVERY);
        setInterval(() => document.visibilityState === 'visible' && navigator.onLine && this.queue.length === 0 && this.refresh(), POLL_EVERY);
        this.flush();
    }

    changed() {
        this.dispatchEvent(new Event('change'));
    }

    /**
     * A presence supersedes the previous one still waiting: only the latest is worth sending.
     */
    async enqueueOperation(operation) {
        if (!this.canKeep()) {
            return this.blocked();
        }

        if (operation.op === 'presence') {
            for (const queued of this.queue.filter((item) => item.type === 'op' && item.op.op === 'presence')) {
                await this.remove(queued.id);
            }
        }

        return this.enqueue({ type: 'op', op: { ...operation, base_revision: this.record?.revision ?? null } });
    }

    /**
     * Sends again an edit refused as a conflict, this time over what someone else saved.
     */
    async keepMine(id) {
        const item = this.rejected.find((candidate) => candidate.id === id);

        if (!item) {
            return;
        }

        if (!this.canKeep()) {
            this.blocked();

            return;
        }

        await this.dismissRejected(id);
        await this.enqueue({ type: 'op', op: { ...item.op, keep_mine: true } });
    }

    async enqueuePhoto(meta, blob) {
        return this.enqueue({ type: 'photo', meta, blob });
    }

    /**
     * Queues an action of the design office workflow (start a box, hand it over, submit it, write in its chat): sent in
     * order with the rest, to its own address, with the queue id as operation id so a resent one is a repeat.
     *
     * @param {{action: string, box: number, url: string, body: object}} flow
     */
    async enqueueFlow(flow) {
        return this.enqueue({ type: 'flow', flow });
    }

    /**
     * Whether something done now can be kept until it is sent: always with storage on the phone, only online without.
     */
    canKeep() {
        return !this.stopped && (storage.persistent || navigator.onLine);
    }

    blocked() {
        this.dispatchEvent(new Event('blocked'));

        return null;
    }

    async enqueue(item) {
        if (!this.canKeep()) {
            return this.blocked();
        }

        const queued = { ...item, id: newId(), cabinet: this.cabinet, seq: nextSeq(), created_at: Date.now(), userId: this.userId, waited: !navigator.onLine };

        await dbPut('queue', queued);
        this.queue.push(queued);
        this.changed();
        this.flush();

        return queued.id;
    }

    async remove(id) {
        await dbDelete('queue', id);
        forgetBlob(id);
        this.queue = this.queue.filter((item) => item.id !== id);
        this.changed();
    }

    async dismissRejected(id) {
        await dbDelete('rejected', id);
        forgetBlob(id);
        this.rejected = this.rejected.filter((item) => item.id !== id);
        this.changed();
    }

    /**
     * Sends an operation at once, without queueing it, for what only works with a network (managers' actions).
     *
     * @returns {Promise<{ok: boolean, error?: string}>}
     */
    async sendNow(operation) {
        if (!navigator.onLine) {
            return { ok: false, error: 'offline' };
        }

        try {
            const response = await fetch(this.urls.operations, this.request({ type: 'op', op: { ...operation, base_revision: this.record?.revision ?? null }, id: newId(), created_at: Date.now() }));
            const answer = await response.json().catch(() => ({}));

            if (await this.lostAccess(response, answer)) {
                return { ok: false, error: answer.error ?? 'invalid' };
            }

            if (!response.ok) {
                return { ok: false, error: answer.error ?? 'invalid' };
            }

            await this.refresh(true);

            return { ok: true };
        } catch {
            return { ok: false, error: 'offline' };
        }
    }

    /**
     * Posts to another online-only endpoint of the audit, then refreshes the record.
     *
     * @returns {Promise<{ok: boolean, error?: string}>}
     */
    async postNow(url) {
        if (!navigator.onLine) {
            return { ok: false, error: 'offline' };
        }

        try {
            const response = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf(), 'X-Requested-With': 'XMLHttpRequest' } });

            if (!response.ok) {
                return { ok: false, error: 'invalid' };
            }

            await this.refresh(true);

            return { ok: true };
        } catch {
            return { ok: false, error: 'offline' };
        }
    }

    async flush() {
        if (this.stopped || this.flushing || this.queue.length === 0 || !navigator.onLine) {
            return;
        }

        this.flushing = true;
        this.problem = null;
        this.changed();

        try {
            // What is added while sending is sent in the same run: every outcome but « stop » takes the item off the queue.
            while (this.queue.length > 0) {
                if ((await this.send(this.queue[0])) === 'stop') {
                    break;
                }
            }
        } finally {
            this.flushing = false;
            this.changed();
        }

        if (this.queue.length === 0 && document.visibilityState === 'visible') {
            await this.refresh(true);
        }
    }

    urlOf(item) {
        return { op: this.urls.operations, flow: item.flow?.url }[item.type] ?? this.urls.photos;
    }

    async send(item) {
        let response;

        try {
            response = await fetch(this.urlOf(item), this.request(item));
        } catch {
            this.online = false;
            await this.markWaited(item);
            this.retryLater();

            return 'stop';
        }

        this.online = true;

        if (response.ok) {
            await this.done(item, item.type === 'op' ? null : await response.json().catch(() => ({})));

            return 'next';
        }

        const answer = await response.json().catch(() => ({}));
        const reason = answer.error ?? answer.reason ?? 'invalid';

        if (await this.lostAccess(response, answer)) {
            return 'stop';
        }

        switch (outcomeOf(item.type, response.status, reason)) {
            case 'auth':
                await this.markWaited(item);
                this.problem = 'auth';
                this.changed();

                return 'stop';
            case 'retry':
                await this.markWaited(item);
                this.problem = 'server';
                this.retryLater();

                return 'stop';
            case 'conflict':
                await this.reject(item, reason, response.status, answer.conflict ?? null);

                return 'next';
            case 'done':
                await this.done(item);

                return 'next';
            default:
                await this.reject(item, reason, response.status, null, item.type === 'flow' ? (answer.message ?? null) : null);

                return 'next';
        }
    }

    /**
     * The server has the item: it leaves the queue, and a photo stays on the phone as a local copy (only the person
     * removes it). Failing to keep the copy (no space left) never holds up the queue. The answer to a design office
     * action (where the box stands now) is passed on to the screen.
     */
    async done(item, answer = null) {
        if (item.type === 'photo') {
            try {
                await keepLocalCopy(item);
            } catch {
                // The server has the photo; the phone simply keeps no copy of it.
            }

            if (Number.isInteger(answer?.photo)) {
                await this.photoReceived(item.id, answer.photo);
            }
        }

        await this.remove(item.id);

        if (item.type === 'flow') {
            this.dispatchEvent(new CustomEvent('flow', { detail: { item, answer } }));
        }
    }

    /**
     * A photo reached the server: the design office actions still waiting that name it (an occurrence and its « before »
     * photos, a correction and its « after » photos…) now carry its id. They were queued after it, so they are sent after.
     */
    async photoReceived(queueId, photoId) {
        for (const queued of this.queue.filter((candidate) => candidate.type === 'flow')) {
            const body = withPhotoId(queued.flow.body, queueId, photoId);

            if (body !== queued.flow.body) {
                queued.flow = { ...queued.flow, body };

                try {
                    await dbPut('queue', queued);
                } catch {
                    // Only this page knows the id; a reload sends the action without that photo.
                }
            }
        }
    }

    /**
     * The server answered that the access is gone (revoked: wipe and sign in again) or that this code is out of date
     * (reload). Either way nothing more is sent from this page.
     */
    async lostAccess(response, answer) {
        if (isRevoked(response.status, answer)) {
            await wipeAndNotify();
            this.stop('revoked');

            return true;
        }

        if (response.status === 426) {
            this.stop('update');

            return true;
        }

        return false;
    }

    stop(problem) {
        this.stopped = true;
        this.problem = problem;
        clearTimeout(this.retryTimer);
        this.changed();
        this.dispatchEvent(new Event(problem));
    }

    /**
     * An item that could not be sent at once has waited in the queue: the server may keep the time of the phone.
     */
    async markWaited(item) {
        if (item.waited) {
            return;
        }

        item.waited = true;

        try {
            await dbPut('queue', item);
        } catch {
            // Only the time of the server will be kept for this item.
        }
    }

    request(item) {
        const headers = { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf(), 'X-Requested-With': 'XMLHttpRequest', 'X-Sync-Version': String(PROTOCOL_VERSION) };
        const timing = { client_created_at: new Date(item.created_at ?? Date.now()).toISOString(), queued: item.waited === true };

        if (item.type === 'op') {
            return { method: 'POST', credentials: 'same-origin', headers: { ...headers, 'Content-Type': 'application/json' }, body: JSON.stringify({ op: { ...item.op, device: this.deviceId, operation_id: item.id, ...timing } }) };
        }

        if (item.type === 'flow') {
            return { method: 'POST', credentials: 'same-origin', headers: { ...headers, 'Content-Type': 'application/json' }, body: JSON.stringify({ ...resolvedBody(item.flow.body), operation_id: item.id, ...timing }) };
        }

        const form = new FormData();
        form.append('operation_id', item.id);
        form.append('client_created_at', timing.client_created_at);
        form.append('queued', timing.queued ? '1' : '0');

        for (const [key, value] of Object.entries(item.meta)) {
            if (value !== null && value !== undefined && value !== '') {
                form.append(key, value);
            }
        }

        form.append('file', item.blob, 'photo.jpg');

        return { method: 'POST', credentials: 'same-origin', headers, body: form };
    }

    async reject(item, reason, status, conflict = null, message = null) {
        const kept = { ...item, reason, status, conflict, message, rejected_at: Date.now() };

        await dbPut('rejected', kept);
        this.rejected.push(kept);
        await this.remove(item.id);
        this.dispatchEvent(new CustomEvent('rejected', { detail: kept }));
    }

    retryLater() {
        this.changed();
        clearTimeout(this.retryTimer);
        this.retryTimer = setTimeout(() => this.flush(), RETRY_AFTER);
    }

    async refresh(force = false) {
        if (this.stopped || !navigator.onLine) {
            return false;
        }

        const revision = force ? '' : (this.record?.revision ?? '');

        try {
            const response = await fetch(`${this.urls.snapshot}?revision=${revision}`, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Sync-Version': String(PROTOCOL_VERSION) } });

            if ((response.status === 401 || response.status === 426) && (await this.lostAccess(response, await response.clone().json().catch(() => ({}))))) {
                return false;
            }

            if (response.status === 401 || response.status === 419) {
                this.problem = 'auth';
                this.changed();

                return false;
            }

            if (!response.ok) {
                return false;
            }

            this.lastRefreshAt = Date.now();
            const snapshot = await response.json();

            if (snapshot.unchanged || this.stopped) {
                return false;
            }

            this.record = { cabinet: this.cabinet, userId: this.userId, revision: snapshot.revision, snapshot, saved_at: Date.now(), opened_at: Date.now() };
            await dbPut('records', this.record);
            this.changed();

            return true;
        } catch {
            this.online = false;
            this.changed();

            return false;
        }
    }

    get pending() {
        return this.queue.length;
    }

    /**
     * Photos of this phone (every audit) waiting to be sent for more than 24 h.
     */
    get stalePhotos() {
        return stalePhotoCount([...this.otherPhotos, ...this.queue], Date.now());
    }
}
