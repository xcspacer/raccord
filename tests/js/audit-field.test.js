import { describe, expect, it, vi } from 'vitest';
import { appendEntry, KEEP } from '../../resources/js/components/error-log';
import { needsCatchUp as livewireNeedsCatchUp } from '../../resources/js/components/background-pause';
import { needsCatchUp, stalePhotoCount, STALE_AFTER } from '../../resources/js/audit/sync';
import { boxPoint } from '../../resources/js/audit/geo';
import { chipState } from '../../resources/js/audit/sync-chip';

describe('error log', () => {
    it('keeps only the last 40 entries', () => {
        let entries = [];

        for (let index = 0; index < 50; index++) {
            entries = appendEntry(entries, { at: '', kind: 'error', message: String(index) });
        }

        expect(KEEP).toBe(40);
        expect(entries).toHaveLength(40);
        expect(entries[0].message).toBe('10');
        expect(entries[39].message).toBe('49');
    });

    it('starts again from an unreadable log', () => {
        expect(appendEntry(null, { kind: 'render' })).toEqual([{ kind: 'render' }]);
    });
});

describe('back in front', () => {
    it('fetches again only when the last answer is older than 20 s', () => {
        for (const check of [needsCatchUp, livewireNeedsCatchUp]) {
            expect(check(100000, 119000)).toBe(false);
            expect(check(100000, 120001)).toBe(true);
        }
    });
});

describe('photos waiting on the phone', () => {
    it('counts the photos of every audit waiting for more than 24 h', () => {
        const now = 10 * STALE_AFTER;
        const queue = [
            { type: 'photo', cabinet: 'PM1', created_at: now - STALE_AFTER - 1 },
            { type: 'photo', cabinet: 'PM2', created_at: now - 2 * STALE_AFTER },
            { type: 'photo', cabinet: 'PM1', created_at: now - 1000 },
            { type: 'op', cabinet: 'PM1', created_at: now - 2 * STALE_AFTER },
        ];

        expect(stalePhotoCount(queue, now)).toBe(2);
        expect(stalePhotoCount([], now)).toBe(0);
    });
});

describe('conflicts between devices', () => {
    it('keeps a conflicting edit apart until the person keeps theirs or the saved one', async () => {
        const { SyncEngine } = await import('../../resources/js/audit/sync');
        const sent = [];

        globalThis.document ??= { cookie: '', addEventListener: () => {} };
        Object.defineProperty(globalThis, 'navigator', { value: { onLine: true }, configurable: true });
        globalThis.fetch = async (url, request) => {
            sent.push(JSON.parse(request.body).op);

            return sent.length === 1
                ? { ok: false, status: 409, json: async () => ({ ok: false, error: 'conflict', conflict: { field: 'box:BPE-1:note_route', by: 'Bruno' } }) }
                : { ok: true, status: 200, json: async () => ({ ok: true }) };
        };

        const engine = new SyncEngine({ cabinet: 'PM1', urls: { operations: '/ops', snapshot: '/snap' }, userId: 1 });
        engine.refresh = async () => false;
        await engine.init();
        engine.record = { revision: 7 };

        await engine.enqueueOperation({ op: 'box_note', box: 'BPE-1', key: 'route', text: 'Mine' });

        await vi.waitFor(() => expect(engine.rejected).toHaveLength(1));
        expect(sent[0]).toMatchObject({ base_revision: 7, device: engine.deviceId });
        expect(engine.queue).toHaveLength(0);
        expect(engine.rejected[0]).toMatchObject({ reason: 'conflict', conflict: { by: 'Bruno' } });

        await engine.keepMine(engine.rejected[0].id);

        await vi.waitFor(() => expect(engine.queue).toHaveLength(0));
        expect(engine.rejected).toHaveLength(0);
        expect(sent[1]).toMatchObject({ text: 'Mine', keep_mine: true });
    });
});

describe('photos kept on the phone', () => {
    const copy = (id, cabinet, box, point = null, size = 100) => ({ id, cabinet, userId: 1, box, point, size, captured_at: `2026-10-01T10:00:0${id.slice(-1)}Z`, synced_at: 1 });
    const waiting = (id, cabinet, meta) => ({ id, type: 'photo', cabinet, userId: 1, meta, blob: { size: 50 } });

    it('counts what was sent and what is still waiting, by audit and by box or control point', async () => {
        const { summariseLocalPhotos } = await import('../../resources/js/audit/local-photos');
        const audits = summariseLocalPhotos(
            [copy('p1', 'PM2', 'BPE-1'), copy('p2', 'PM2', 'BPE-1'), copy('p3', 'PM2', null, 7), copy('p4', 'PM1', 'BPE-9')],
            [waiting('q1', 'PM2', { box: 'bpe-1 ' }), waiting('q2', 'PM2', { point: '7' })],
            { PM2: { 7: 'Cabinet' } },
        );

        expect(audits.map((audit) => audit.cabinet)).toEqual(['PM1', 'PM2']);
        expect(audits[1]).toMatchObject({ synced: 3, waiting: 2, size: 300 });
        expect(audits[1].subjects.map((subject) => [subject.label, subject.synced, subject.waiting])).toEqual([['BPE-1', 2, 1], ['Cabinet', 1, 1]]);
    });

    it('never offers a photo still waiting to be sent, for one photo, a box or a whole audit', async () => {
        const { deletableIds, summariseLocalPhotos } = await import('../../resources/js/audit/local-photos');
        const audits = summariseLocalPhotos(
            [copy('p1', 'PM1', 'BPE-1'), copy('p2', 'PM1', 'BPE-2'), copy('q1', 'PM1', 'BPE-1')],
            [waiting('q1', 'PM1', { box: 'BPE-1' }), waiting('q2', 'PM1', { box: 'BPE-1' })],
        );
        const box = audits[0].subjects[0];

        expect(deletableIds(audits, { cabinet: 'PM1' })).toEqual(['p1', 'p2']);
        expect(deletableIds(audits, { cabinet: 'PM1', subject: box.key })).toEqual(['p1']);
        expect(deletableIds(audits, { photo: 'q2' })).toEqual([]);
        expect(deletableIds(audits, { photo: 'p2' })).toEqual(['p2']);
        expect(box).toMatchObject({ synced: 1, waiting: 2 });
    });

    it('keeps a sent photo on the phone and removes it from there only, never one still waiting', async () => {
        const { SyncEngine } = await import('../../resources/js/audit/sync');
        const { dbAll } = await import('../../resources/js/audit/db');
        const { deleteLocalCopies } = await import('../../resources/js/audit/local-photos');
        const sent = [];

        globalThis.document ??= { cookie: '', addEventListener: () => {} };
        Object.defineProperty(globalThis, 'navigator', { value: { onLine: true }, configurable: true });
        globalThis.fetch = async (url) => {
            sent.push(url);

            return sent.length === 1 ? { ok: true, status: 200, json: async () => ({ ok: true }) } : { ok: false, status: 503, json: async () => ({}) };
        };

        const engine = new SyncEngine({ cabinet: 'PM5', urls: { operations: '/ops', photos: '/photos', snapshot: '/snap' }, userId: 1 });
        engine.refresh = async () => false;
        engine.retryLater = () => {};
        await engine.init();

        const first = await engine.enqueuePhoto({ box: 'BPE-1', folder: 'tco', subfolder: '' }, new Blob(['sent']));

        await vi.waitFor(() => expect(engine.queue).toHaveLength(0));

        const second = await engine.enqueuePhoto({ box: 'BPE-1', folder: 'tco', subfolder: '' }, new Blob(['waiting']));

        await vi.waitFor(() => expect(sent).toHaveLength(2));
        expect((await dbAll('photos')).map((photo) => photo.id)).toEqual([first]);
        expect(engine.queue.map((item) => item.id)).toEqual([second]);

        expect(await deleteLocalCopies([first, second])).toBe(1);
        expect(await dbAll('photos')).toHaveLength(0);
        expect((await dbAll('queue')).map((item) => item.id)).toEqual([second]);
        expect(sent).toHaveLength(2);
    });
});

describe('formatting a size', () => {
    it('uses the unit that fits', async () => {
        const { formatSize } = await import('../../resources/js/audit/local-photos');

        expect(formatSize(2500000, 'en-GB')).toBe('2.5 MB');
        expect(formatSize(3200000000, 'en-GB')).toBe('3.2 GB');
        expect(formatSize(400, 'en-GB')).toBe('0.4 kB');
    });
});

describe('what keeps a box under 100 %', () => {
    it('lists each missing thing, and nothing once the box is complete', async () => {
        globalThis.document ??= { cookie: '', addEventListener: () => {} };
        const { default: boxScreen } = await import('../../resources/js/audit/box-screen');
        const screen = boxScreen();
        const photo = (folder, subfolder = null) => ({ folder, subfolder });

        screen.config = {
            texts: { todo_photos: 'Photos :folder', todo_cable: ':cable: :missing', cable_n: 'Cable :n', todo_capacity: 'capacity', todo_fibre_type: 'fibre type', todo_cable_label: 'label (or :no_label)', no_label: 'no label', todo_position: 'Position', todo_status: 'Status' },
            labels: { folders: { surroundings: 'Surroundings', interior: 'Interior', closed: 'Closed', open: 'Open', tco: 'TCO' }, poleSections: {} },
        };
        screen.boxName = 'PBO-1';
        screen.view = { viewer: { manager: true }, audit: { mode: 'optical' }, boxes: [{
            name: 'PBO-1', kind: 'pbo', support: 'chamber', status: null, confirmed: null,
            photos: [photo('surroundings'), photo('interior'), photo('closed'), photo('open'), photo('cassettes')],
            cables: [{ code: 'A', capacity: 12, fibre_type: 'g657', has_no_label: false }, { code: 'B', capacity: null, fibre_type: 'g657', has_no_label: true }],
        }] };

        expect(screen.todo).toEqual(['Photos TCO', 'Cable 1: label (or no label)', 'Cable 2: capacity', 'Position', 'Status']);

        Object.assign(screen.view.boxes[0], { status: 'ok', confirmed: { at: 1 } });
        screen.view.boxes[0].photos.push(photo('tco'), photo('labels', 'A'));
        screen.view.boxes[0].cables[1].capacity = 6;

        expect(screen.percent).toBe(100);
        expect(screen.todo).toEqual([]);
    });
});

describe('recheck from the box screen', () => {
    it('opens in edit mode only for a manager editing the box', async () => {
        globalThis.document ??= { cookie: '', addEventListener: () => {} };
        const { default: boxScreen } = await import('../../resources/js/audit/box-screen');
        const screen = boxScreen();

        screen.boxName = 'BPE-1';
        screen.view = { viewer: { manager: true }, audit: { validated: true }, boxes: [{ name: 'BPE-1', recheck: { url: '/audits/cabinet/PM1/recheck/4' } }] };

        expect(screen.recheckUrl).toBe('/audits/cabinet/PM1/recheck/4');

        screen.startEditing();
        expect(screen.recheckUrl).toBe('/audits/cabinet/PM1/recheck/4?edit=1');

        screen.view.viewer.manager = false;
        expect(screen.recheckUrl).toBe('/audits/cabinet/PM1/recheck/4');
    });
});

describe('sync protocol, access and local data', () => {
    const setUp = (onLine = true) => {
        globalThis.document ??= { cookie: '', addEventListener: () => {} };
        Object.defineProperty(globalThis, 'navigator', { value: { onLine }, configurable: true });
    };

    const engineFor = async (userId, cabinet, urls = { operations: '/ops', photos: '/photos', snapshot: '/snap', login: '/login' }) => {
        const { SyncEngine } = await import('../../resources/js/audit/sync');
        const engine = new SyncEngine({ cabinet, urls, userId });

        engine.refresh = async () => false;
        engine.retryLater = () => {};
        await engine.init();

        return engine;
    };

    it('sends the protocol version, when the item was made and whether it waited in the queue', async () => {
        const { PROTOCOL_VERSION } = await import('../../resources/js/audit/sync');
        const requests = [];

        setUp();
        globalThis.fetch = async (url, request) => {
            requests.push(request);

            return requests.length === 1 ? { ok: false, status: 503, json: async () => ({}) } : { ok: true, status: 200, json: async () => ({ ok: true }) };
        };

        const engine = await engineFor(1, 'PM10');
        await engine.enqueueOperation({ op: 'box_note', box: 'BPE-1', key: 'route', text: 'RAS' });
        await vi.waitFor(() => expect(engine.flushing).toBe(false));
        expect(requests).toHaveLength(1);
        await engine.flush();

        const [first, second] = requests.map((request) => JSON.parse(request.body).op);
        expect(requests[0].headers['X-Sync-Version']).toBe(String(PROTOCOL_VERSION));
        expect(first).toMatchObject({ queued: false, operation_id: second.operation_id, client_created_at: second.client_created_at });
        expect(second.queued).toBe(true);
        expect(engine.queue).toHaveLength(0);
    });

    it('keeps an operation the server refuses, as not allowed or as an audit that is not theirs, apart for the person to see', async () => {
        const answers = [
            { ok: false, status: 403, json: async () => ({ ok: false, error: 'forbidden' }) },
            { ok: false, status: 404, json: async () => ({ ok: false, error: 'audit_not_found' }) },
        ];

        setUp();
        globalThis.fetch = async () => answers.shift();

        const engine = await engineFor(1, 'PM14');
        const refused = vi.fn();
        engine.addEventListener('rejected', refused);
        await engine.enqueueOperation({ op: 'box_note', box: 'BPE-1', key: 'route', text: 'RAS' });
        await vi.waitFor(() => expect(engine.flushing).toBe(false));
        await engine.enqueueOperation({ op: 'box_note', box: 'BPE-2', key: 'route', text: 'RAS' });

        await vi.waitFor(() => expect(engine.rejected).toHaveLength(2));
        expect(engine.queue).toHaveLength(0);
        expect(engine.rejected.map((item) => [item.status, item.reason])).toEqual([[403, 'forbidden'], [404, 'audit_not_found']]);
        expect(refused).toHaveBeenCalledTimes(2);

        for (const item of [...engine.rejected]) {
            await engine.dismissRejected(item.id);
        }
    });

    it('stops and asks for a reload when the server expects newer code', async () => {
        setUp();
        globalThis.fetch = async () => ({ ok: false, status: 426, json: async () => ({ ok: false, error: 'update_required' }) });

        const engine = await engineFor(1, 'PM11');
        const update = vi.fn();
        engine.addEventListener('update', update);
        await engine.enqueueOperation({ op: 'box_note', box: 'BPE-1', key: 'route', text: 'RAS' });

        await vi.waitFor(() => expect(update).toHaveBeenCalledOnce());
        expect(engine.problem).toBe('update');
        expect(engine.queue).toHaveLength(1);
        expect(engine.rejected).toHaveLength(0);
        await engine.remove(engine.queue[0].id);
    });

    it('sends the protocol version with the snapshot request and stops when the server expects newer code', async () => {
        const { SyncEngine, PROTOCOL_VERSION } = await import('../../resources/js/audit/sync');
        const requests = [];
        const answer = { ok: false, status: 426, json: async () => ({ ok: false, error: 'update_required' }) };

        setUp();
        globalThis.fetch = async (url, request) => {
            requests.push(request);

            return { ...answer, clone: () => answer };
        };

        const engine = await engineFor(1, 'PM13');
        const update = vi.fn();
        engine.addEventListener('update', update);

        expect(await SyncEngine.prototype.refresh.call(engine, true)).toBe(false);
        expect(requests[0].headers['X-Sync-Version']).toBe(String(PROTOCOL_VERSION));
        expect(update).toHaveBeenCalledOnce();
        expect(engine.problem).toBe('update');
    });

    it('keeps the queue on a plain 401 but wipes everything except pending items when access is revoked', async () => {
        const { isRevoked } = await import('../../resources/js/audit/sync');
        const { dbAll, dbPut } = await import('../../resources/js/audit/db');

        expect(isRevoked(401, { reason: 'session_revoked' })).toBe(true);
        expect(isRevoked(401, { error: 'user_disabled' })).toBe(true);
        expect(isRevoked(401, { message: 'Unauthenticated.' })).toBe(false);
        expect(isRevoked(403, { reason: 'session_revoked' })).toBe(false);

        setUp();
        let answer = { message: 'Unauthenticated.' };
        globalThis.fetch = async () => ({ ok: false, status: 401, json: async () => answer });

        const engine = await engineFor(1, 'PM12');
        const revoked = vi.fn();
        engine.addEventListener('revoked', revoked);
        await dbPut('records', { cabinet: 'PM12', userId: 1, revision: 1, snapshot: {}, saved_at: Date.now() });
        await dbPut('photos', { id: 'copy1', cabinet: 'PM12', userId: 1, synced_at: Date.now() });
        await engine.enqueueOperation({ op: 'box_note', box: 'BPE-1', key: 'route', text: 'RAS' });

        await vi.waitFor(() => expect(engine.problem).toBe('auth'));
        expect(revoked).not.toHaveBeenCalled();
        expect(await dbAll('records')).not.toHaveLength(0);

        answer = { ok: false, reason: 'session_revoked' };
        engine.problem = null;
        await engine.flush();

        expect(revoked).toHaveBeenCalledOnce();
        expect(engine.stopped).toBe(true);
        expect(await dbAll('records')).toHaveLength(0);
        expect(await dbAll('photos')).toHaveLength(0);
        expect((await dbAll('queue')).filter((item) => item.cabinet === 'PM12')).toHaveLength(1);
    });

    it('never loads nor sends the pending items of another person, and sends them when that person is back', async () => {
        const { dbAll, pendingCount } = await import('../../resources/js/audit/db');
        const sent = [];

        setUp();
        globalThis.fetch = async (url, request) => {
            sent.push(JSON.parse(request.body).op.text);

            return { ok: true, status: 200, json: async () => ({ ok: true }) };
        };

        const other = await engineFor(2, 'PM12');
        expect(other.queue).toHaveLength(0);
        await other.flush();
        expect(sent).toEqual([]);
        expect(await pendingCount()).toBe(0);
        expect((await dbAll('queue')).filter((item) => item.userId === 1 && item.cabinet === 'PM12')).toHaveLength(1);

        const owner = await engineFor(1, 'PM12');
        const waiting = await pendingCount();
        expect(waiting).toBeGreaterThan(0);
        expect(owner.queue).toHaveLength(1);
        await owner.flush();
        expect(sent).toEqual(['RAS']);
        expect(await pendingCount()).toBe(waiting - 1);
    });

    it('refuses work done offline when the phone cannot keep it', async () => {
        const { storage } = await import('../../resources/js/audit/db');

        setUp(false);
        const engine = await engineFor(1, 'PM13');
        const blocked = vi.fn();
        engine.addEventListener('blocked', blocked);
        storage.persistent = false;

        expect(await engine.enqueueOperation({ op: 'box_note', box: 'BPE-1', key: 'route', text: 'RAS' })).toBeNull();
        expect(engine.queue).toHaveLength(0);
        expect(blocked).toHaveBeenCalledOnce();

        storage.persistent = true;
        expect(await engine.enqueueOperation({ op: 'box_note', box: 'BPE-1', key: 'route', text: 'RAS' })).not.toBeNull();
        expect(engine.queue[0].waited).toBe(true);
        await engine.remove(engine.queue[0].id);
        storage.persistent = false;
    });

    it('drops audits not opened for 30 days, never what is pending', async () => {
        const { expiredRecords, LOCAL_TTL } = await import('../../resources/js/audit/db');
        const now = 100 * LOCAL_TTL;
        const old = now - LOCAL_TTL - 1;

        const expired = expiredRecords({
            records: [{ cabinet: 'PM1', saved_at: old }, { cabinet: 'PM2', saved_at: old, opened_at: now - 1000 }, { cabinet: 'PM3', saved_at: old }],
            pending: [{ id: 'p3', cabinet: 'PM3' }],
        }, now);

        expect(expired).toEqual(['PM1']);
    });

    it('keeps the copies of sent photos however old they are, until the person removes them', async () => {
        const { dbAll, dbPut, dropExpired, LOCAL_TTL } = await import('../../resources/js/audit/db');
        const now = 100 * LOCAL_TTL;

        await dbPut('photos', { id: 'kept-old', cabinet: 'PM9', synced_at: now - 10 * LOCAL_TTL });
        await dropExpired(now);

        expect((await dbAll('photos')).map((photo) => photo.id)).toContain('kept-old');
    });
});

describe('where « J\'y vais » leads', () => {
    it('prefers the official position of the design office, then the plans, then the position confirmed on site', () => {
        const official = { latitude: 1, longitude: 1 };
        const plans = { latitude: 2, longitude: 2 };
        const confirmed = { latitude: 3, longitude: 3 };

        expect(boxPoint({ official, position: plans, confirmed })).toBe(official);
        expect(boxPoint({ official: null, position: plans, confirmed })).toBe(plans);
        expect(boxPoint({ position: null, confirmed })).toBe(confirmed);
        expect(boxPoint({ position: null, confirmed: null })).toBeNull();
    });
});

describe('sync chip of the audit list and the cabinet page', () => {
    it('says synced, pending or offline as the field screens do', () => {
        expect(chipState(true, 0)).toEqual({ tone: 'ok', label: 'synced' });
        expect(chipState(true, 3)).toEqual({ tone: 'wait', label: 'pending' });
        expect(chipState(false, 0)).toEqual({ tone: 'off', label: 'offline' });
        expect(chipState(false, 2)).toEqual({ tone: 'off', label: 'offline_pending' });
    });
});
