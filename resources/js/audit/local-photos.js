import { dbAll, dbDelete, dbGet, dbPut } from './db';
import { forgetBlob } from './replay';

/**
 * The photos kept on this phone. Sending a photo never removes it from the phone: once the server has it, the queue
 * item becomes a local copy that stays until the person removes it. Removing a copy only frees space on the phone (the
 * screens show the photo from the server); a photo still waiting to be sent, or refused by the server, is never removed
 * from here.
 */
const normaliseName = (name) => String(name ?? '').replace(/\s+/g, ' ').trim().toUpperCase();

/**
 * Keeps the photo of a queue item the server has just accepted.
 */
export async function keepLocalCopy(item, syncedAt = Date.now()) {
    const meta = item.meta ?? {};

    await dbPut('photos', {
        id: item.id,
        cabinet: item.cabinet,
        userId: item.userId,
        box: meta.box ? normaliseName(meta.box) : null,
        point: meta.box ? null : Number(meta.point ?? 0) || null,
        captured_at: meta.captured_at ?? null,
        size: item.blob?.size ?? 0,
        blob: item.blob,
        created_at: item.created_at ?? syncedAt,
        synced_at: syncedAt,
    });
}

/**
 * A copy can go only when the server has it and nothing with the same identifier is still waiting on the phone.
 */
export const isDeletable = (photo, waitingIds) => Boolean(photo.synced_at) && !waitingIds.has(photo.id);

const subjectOf = (cabinet, box, point) => (box ? `${cabinet}|box|${box}` : `${cabinet}|point|${point ?? 0}`);

const byName = (first, second) => first.localeCompare(second, undefined, { numeric: true });

/**
 * The photos of the phone by audit, then by box or control point.
 *
 * @param {Array} copies Copies of photos the server already has.
 * @param {Array} waiting Photo items still waiting to be sent or refused by the server (every audit).
 * @param {Record<string, Record<number, string>>} pointNames Names of the control points by audit.
 */
export function summariseLocalPhotos(copies, waiting, pointNames = {}) {
    const waitingIds = new Set(waiting.map((item) => item.id));
    const audits = new Map();

    const add = (cabinet, box, point, photo) => {
        if (!audits.has(cabinet)) {
            audits.set(cabinet, { cabinet, synced: 0, waiting: 0, size: 0, subjects: new Map() });
        }

        const audit = audits.get(cabinet);
        const key = subjectOf(cabinet, box, point);

        if (!audit.subjects.has(key)) {
            audit.subjects.set(key, { key, cabinet, label: box ?? pointNames[cabinet]?.[point] ?? `#${point ?? '?'}`, synced: 0, waiting: 0, size: 0, photos: [] });
        }

        const subject = audit.subjects.get(key);

        subject.photos.push(photo);

        for (const group of [audit, subject]) {
            group[photo.deletable ? 'synced' : 'waiting']++;
            group.size += photo.deletable ? photo.size : 0;
        }
    };

    for (const copy of copies.filter((candidate) => !waitingIds.has(candidate.id))) {
        add(copy.cabinet, copy.box, copy.point, { id: copy.id, size: copy.size ?? 0, captured_at: copy.captured_at, deletable: isDeletable(copy, waitingIds), blob: copy.blob });
    }

    for (const item of waiting) {
        const box = item.meta?.box ? normaliseName(item.meta.box) : null;

        add(item.cabinet, box, box ? null : Number(item.meta?.point ?? 0) || null, { id: item.id, size: item.blob?.size ?? 0, captured_at: item.meta?.captured_at, deletable: false, blob: item.blob });
    }

    return [...audits.values()].sort((first, second) => byName(first.cabinet, second.cabinet)).map((audit) => ({
        ...audit,
        subjects: [...audit.subjects.values()].sort((first, second) => byName(first.label, second.label)).map((subject) => ({
            ...subject,
            photos: subject.photos.sort((first, second) => String(first.captured_at ?? '').localeCompare(String(second.captured_at ?? ''))),
        })),
    }));
}

/**
 * Identifiers of the copies that can be removed, for one photo, a box / control point or a whole audit.
 *
 * @param {Array} audits What summariseLocalPhotos returned.
 * @param {{cabinet?: string, subject?: string, photo?: string}} scope
 */
export function deletableIds(audits, scope) {
    return audits
        .filter((audit) => scope.cabinet === undefined || audit.cabinet === scope.cabinet)
        .flatMap((audit) => audit.subjects)
        .filter((subject) => scope.subject === undefined || subject.key === scope.subject)
        .flatMap((subject) => subject.photos)
        .filter((photo) => photo.deletable && (scope.photo === undefined || photo.id === scope.photo))
        .map((photo) => photo.id);
}

/**
 * Everything the phone keeps about the photos of this person.
 */
export async function loadLocalPhotos(userId) {
    const mine = (item) => item.userId === undefined || item.userId === userId;
    const [copies, queue, rejected, records] = await Promise.all([dbAll('photos'), dbAll('queue'), dbAll('rejected'), dbAll('records')]);
    const waiting = [...queue, ...rejected].filter((item) => item.type === 'photo' && mine(item));
    const pointNames = Object.fromEntries(records.map((record) => [record.cabinet, Object.fromEntries((record.snapshot?.points ?? []).map((point) => [point.id, point.name]))]));

    return summariseLocalPhotos(copies.filter(mine), waiting, pointNames);
}

/**
 * Removes copies from the phone only (the server is never asked anything). Each one is checked again against what
 * is waiting at this very moment, so a photo not yet sent can never be removed.
 *
 * @returns {Promise<number>} How many copies were removed.
 */
export async function deleteLocalCopies(ids) {
    const waitingIds = new Set([...(await dbAll('queue')), ...(await dbAll('rejected'))].map((item) => item.id));
    let removed = 0;

    for (const id of ids) {
        const copy = await dbGet('photos', id);

        if (copy && isDeletable(copy, waitingIds)) {
            await dbDelete('photos', id);
            forgetBlob(id);
            removed++;
        }
    }

    return removed;
}

/**
 * What the browser says this site uses on the phone, when it can tell.
 *
 * @returns {Promise<{usage: number, quota: number}|null>}
 */
export async function storageUsage() {
    try {
        const estimate = await navigator.storage?.estimate?.();

        return estimate && typeof estimate.usage === 'number' ? { usage: estimate.usage, quota: estimate.quota ?? 0 } : null;
    } catch {
        return null;
    }
}

/**
 * A size in the units of the person's language (« 12,3 Mo », « 12.3 MB »…).
 */
export function formatSize(bytes, locale) {
    const units = [['gigabyte', 1e9], ['megabyte', 1e6], ['kilobyte', 1e3]];
    const [unit, size] = units.find(([, value]) => bytes >= value) ?? units[2];

    return new Intl.NumberFormat(locale, { style: 'unit', unit, maximumFractionDigits: 1 }).format(bytes / size);
}
