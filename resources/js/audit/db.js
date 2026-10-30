/**
 * Local storage of the audit screen: the last audit received from the server, the queue of what the person did
 * while offline, what the server refused, the copies of the photos already sent (see local-photos.js) and where each
 * box stands in the design office workflow as last read (see box-workflow.js). IndexedDB when the browser allows it;
 * otherwise (some private modes) a copy in memory, which is lost when the page closes — offline work is then refused
 * (see `storage`).
 *
 * Queue and refused items carry the person who made them: they are « pending » and stay on the phone until sent or
 * dismissed by that person, whoever signs in meanwhile. Everything else is a copy of what the server has: it is wiped
 * when the person signs out or loses access. Audits are also dropped after LOCAL_TTL without being opened; the copies
 * of the photos already sent stay until the person removes them (see local-photos.js).
 */
const DATABASE = 'audit_field';
const STORES = { records: 'cabinet', queue: 'id', rejected: 'id', meta: 'key', photos: 'id', workflow: 'box' };
const VERSION = 3;

export const storage = { persistent: false };

class MemoryBackend {
    constructor() {
        this.data = Object.fromEntries(Object.keys(STORES).map((name) => [name, new Map()]));
    }

    async get(store, key) {
        return this.data[store].get(key);
    }

    async put(store, value) {
        this.data[store].set(value[STORES[store]], value);
    }

    async delete(store, key) {
        this.data[store].delete(key);
    }

    async all(store) {
        return [...this.data[store].values()];
    }

    async clear(store) {
        this.data[store].clear();
    }
}

class IndexedBackend {
    constructor(database) {
        this.database = database;
    }

    run(store, mode, action) {
        return new Promise((resolve, reject) => {
            const transaction = this.database.transaction(store, mode);
            const request = action(transaction.objectStore(store));
            transaction.oncomplete = () => resolve(request?.result);
            transaction.onerror = () => reject(transaction.error);
            transaction.onabort = () => reject(transaction.error);
        });
    }

    get(store, key) {
        return this.run(store, 'readonly', (objects) => objects.get(key));
    }

    put(store, value) {
        return this.run(store, 'readwrite', (objects) => objects.put(value));
    }

    delete(store, key) {
        return this.run(store, 'readwrite', (objects) => objects.delete(key));
    }

    all(store) {
        return this.run(store, 'readonly', (objects) => objects.getAll());
    }

    clear(store) {
        return this.run(store, 'readwrite', (objects) => objects.clear());
    }
}

const open = () => new Promise((resolve, reject) => {
    if (!window.indexedDB) {
        reject(new Error('no indexedDB'));

        return;
    }

    const timer = setTimeout(() => reject(new Error('indexedDB timeout')), 4000);
    const request = window.indexedDB.open(DATABASE, VERSION);

    request.onupgradeneeded = () => {
        for (const [name, key] of Object.entries(STORES)) {
            if (!request.result.objectStoreNames.contains(name)) {
                request.result.createObjectStore(name, { keyPath: key });
            }
        }
    };
    request.onsuccess = () => {
        clearTimeout(timer);
        resolve(request.result);
    };
    request.onerror = () => {
        clearTimeout(timer);
        reject(request.error);
    };
});

let backend = null;

const ready = async () => {
    if (backend) {
        return backend;
    }

    try {
        backend = new IndexedBackend(await open());
        storage.persistent = true;
    } catch {
        backend = new MemoryBackend();
        storage.persistent = false;
    }

    return backend;
};

export const dbGet = async (store, key) => (await ready()).get(store, key);
export const dbPut = async (store, value) => (await ready()).put(store, value);
export const dbDelete = async (store, key) => (await ready()).delete(store, key);
export const dbAll = async (store) => (await ready()).all(store);
export const dbClear = async (store) => (await ready()).clear(store);

export const PENDING_STORES = ['queue', 'rejected'];
export const LOCAL_TTL = 30 * 24 * 3600 * 1000;
const AUDIT_KEYS = /^audit_(navigation$|seen_)/;

/**
 * Whether the browser already has the database, without creating it (when the browser can tell).
 */
const exists = async () => {
    const factory = globalThis.indexedDB;

    try {
        const databases = await factory?.databases?.();

        return databases === undefined ? Boolean(factory) : databases.some((database) => database.name === DATABASE);
    } catch {
        return Boolean(factory);
    }
};

/**
 * How many items made by the person last signed in on this phone are still waiting to be sent or refused.
 */
export async function pendingCount() {
    if (!backend && !(await exists())) {
        return 0;
    }

    const userId = (await dbGet('meta', 'user'))?.userId;
    const items = (await Promise.all(PENDING_STORES.map((store) => dbAll(store)))).flat();

    return items.filter((item) => item.userId === userId).length;
}

/**
 * Removes from the phone what the audit screens keep, except the pending items of every person (and the identifier
 * of the device, which says nothing about anyone).
 */
export async function wipeLocalData() {
    try {
        const local = globalThis.localStorage;

        for (const key of local ? Object.keys(local).filter((name) => AUDIT_KEYS.test(name)) : []) {
            local.removeItem(key);
        }
    } catch {
        // Storage refused: nothing was kept there either.
    }

    if (!backend && !(await exists())) {
        return;
    }

    for (const store of Object.keys(STORES).filter((name) => !PENDING_STORES.includes(name) && name !== 'meta')) {
        await dbClear(store);
    }

    await dbDelete('meta', 'user');
}

/**
 * The audits not opened for LOCAL_TTL and no longer needed by anything pending: audits with pending items of any
 * person stay. Photo copies are never dropped here, only by the person.
 *
 * @returns {string[]} Cabinets to drop.
 */
export function expiredRecords({ records, pending }, now) {
    const limit = now - LOCAL_TTL;
    const busy = new Set(pending.map((item) => item.cabinet));

    return records.filter((record) => Math.max(record.saved_at ?? 0, record.opened_at ?? 0) < limit && !busy.has(record.cabinet)).map((record) => record.cabinet);
}

/**
 * Drops what expiredRecords finds.
 */
export async function dropExpired(now = Date.now()) {
    const pending = (await Promise.all(PENDING_STORES.map((store) => dbAll(store)))).flat();

    for (const cabinet of expiredRecords({ records: await dbAll('records'), pending }, now)) {
        await dbDelete('records', cabinet);
    }
}
